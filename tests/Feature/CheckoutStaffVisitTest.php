<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Services\PaymentLinkService;
use Tests\TestCase;

/**
 * "Open link" on the invoice list opens a signed copy of the customer's link, so staff
 * checking the page are logged as staff and never look like the customer viewing it.
 */
class CheckoutStaffVisitTest extends TestCase
{
    use RefreshDatabase;

    private PaymentSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'quickbooks',
            'pms_client_id'       => 'qb-client-1',
            'external_invoice_id' => '1199',
            'external_client_id'  => '191',
            'amount_cents'        => 70063,
            'currency'            => 'USD',
            'fund_type'           => 'OPERATING',
            'status'              => 'SENT',
        ]);

        $this->session = PaymentSession::query()->forceCreate([
            'invoice_id'       => $invoice->id,
            'hosted_url_token' => Str::random(40),
            'status'           => 'PENDING',
            'fund_type'        => 'OPERATING',
            'idempotency_key'  => 'idem-'.Str::random(8),
            'link_status'      => 'active',
            'expires_at'       => now()->addDays(30),
        ]);
    }

    public function test_a_visit_from_open_link_is_logged_as_staff_for_every_step(): void
    {
        $this->get($this->path(app(PaymentLinkService::class)->staffViewUrl($this->session, 'user:5')))->assertOk();

        $opened = $this->latest('CHECKOUT_PAGE_OPENED');
        $this->assertSame('staff', $opened->actor_type);
        $this->assertSame('user:5', $opened->payload['viewer']);

        // Later steps the page reports carry the same visit id.
        $this->postJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}/events",
            ['event' => 'page_closed', 'detail' => ['last_step' => 'page_opened']],
            ['X-Checkout-Visit' => $opened->payload['visit_id']],
        )->assertNoContent();

        $this->assertSame('staff', $this->latest('CHECKOUT_PAGE_CLOSED')->actor_type);
    }

    public function test_the_customers_link_is_logged_as_the_payer(): void
    {
        $this->get($this->path(app(PaymentLinkService::class)->urlForSession($this->session)))->assertOk();

        $opened = $this->latest('CHECKOUT_PAGE_OPENED');
        $this->assertSame('payer', $opened->actor_type);
        $this->assertArrayNotHasKey('viewer', $opened->payload);
    }

    public function test_a_tampered_or_expired_staff_link_is_logged_as_the_payer(): void
    {
        $signed = $this->path(app(PaymentLinkService::class)->staffViewUrl($this->session, 'user:5'));

        $this->get(str_replace('user%3A5', 'user%3A6', $signed))->assertOk();
        $this->assertSame('payer', $this->latest('CHECKOUT_PAGE_OPENED')->actor_type);

        $this->travel(13)->hours();
        $this->get($signed)->assertOk();
        $this->assertSame('payer', $this->latest('CHECKOUT_PAGE_OPENED')->actor_type);
    }

    public function test_a_customer_cannot_claim_a_visit_id_they_made_up(): void
    {
        $this->postJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}/events",
            ['event' => 'page_closed'],
            ['X-Checkout-Visit' => (string) Str::uuid()],
        )->assertNoContent();

        $this->assertSame('payer', $this->latest('CHECKOUT_PAGE_CLOSED')->actor_type);
    }

    private function path(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function latest(string $event): AuditLog
    {
        return AuditLog::query()->where('event_type', $event)->orderByDesc('id')->firstOrFail();
    }
}
