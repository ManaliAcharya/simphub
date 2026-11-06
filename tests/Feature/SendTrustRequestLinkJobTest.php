<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Jobs\SendTrustRequestLinkJob;
use Modules\Inbound\Services\LawcusMatterActivityService;
use Modules\Payment\Services\InvoiceLiveStatusResolver;
use Modules\Payment\Services\PaymentLinkService;
use Tests\TestCase;

class SendTrustRequestLinkJobTest extends TestCase
{
    use RefreshDatabase;

    private function trustRequest(): array
    {
        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => 'firm-1',
            'external_invoice_id' => 'TR-1',
            'external_client_id'  => 'contact-1',
            'amount_cents'        => 150000,
            'currency'            => 'USD',
            'fund_type'           => 'TRUST',
            'status'              => 'APPROVED',
            'recipient_emails'    => ['client@example.com'],
        ]);

        $session = PaymentSession::query()->forceCreate([
            'invoice_id'       => $invoice->id,
            'hosted_url_token' => Str::random(40),
            'status'           => 'AWAITING_PAYMENT',
            'fund_type'        => 'TRUST',
            'idempotency_key'  => 'idem-'.Str::random(8),
            'link_status'      => 'active',
            'expires_at'       => now()->addDays(30),
        ]);

        return [$invoice, $session];
    }

    private function run_job(Invoice $invoice, string $status, int $expectedSends): void
    {
        $resolver = Mockery::mock(InvoiceLiveStatusResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($status);

        $links = Mockery::mock(PaymentLinkService::class);
        $links->shouldReceive('sendInvoiceLinkOnce')->times($expectedSends)->andReturn(1);

        $activity = Mockery::mock(LawcusMatterActivityService::class);
        $activity->shouldReceive('logInvoiceSent')->times($expectedSends);

        (new SendTrustRequestLinkJob((string) $invoice->id))->handle($resolver, $links, $activity);
    }

    public function test_link_is_sent_when_the_trust_request_is_still_unpaid(): void
    {
        [$invoice] = $this->trustRequest();

        $this->run_job($invoice, 'unpaid', 1);
    }

    public function test_nothing_is_sent_once_the_firm_has_recorded_the_payment(): void
    {
        [$invoice, $session] = $this->trustRequest();

        $this->run_job($invoice, 'paid', 0);

        $this->assertSame('paid', $session->fresh()->link_status);
    }

    public function test_a_link_already_sent_is_not_sent_again(): void
    {
        [$invoice, $session] = $this->trustRequest();
        $session->forceFill(['payment_link_sent_at' => now()])->save();

        $this->run_job($invoice, 'unpaid', 0);
    }

    public function test_job_waits_thirty_seconds_and_runs_once_per_trust_request(): void
    {
        Queue::fake();
        [$invoice] = $this->trustRequest();

        SendTrustRequestLinkJob::dispatchDelayed($invoice);
        SendTrustRequestLinkJob::dispatchDelayed($invoice);

        Queue::assertPushed(SendTrustRequestLinkJob::class, 1);
        Queue::assertPushed(SendTrustRequestLinkJob::class, fn ($job) => $job->delay !== null
            && abs(now()->diffInSeconds($job->delay)) >= 29);
    }
}
