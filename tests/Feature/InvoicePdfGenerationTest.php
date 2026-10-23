<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Payment\Services\InvoicePdfService;
use Tests\TestCase;

class InvoicePdfGenerationTest extends TestCase
{
    private function invoice(int $amountCents = 14585): Invoice
    {
        return (new Invoice)->forceFill([
            'id'             => '01a159c9-0000-7000-8000-000000000001',
            'pms_source'     => 'custom',
            'invoice_number' => '1653',
            'amount_cents'   => $amountCents,
            'currency'       => 'USD',
            'customer'       => ['name' => 'Test Customer', 'email' => 'customer@example.com'],
            'raw_payload'    => ['invoice' => ['number' => '1653']],
        ]);
    }

    public function test_pdf_is_generated_at_send_time_and_nothing_is_stored(): void
    {
        Storage::fake('local');

        $pdf = app(InvoicePdfService::class)->generate($this->invoice(), 'https://simphub.io/pay/abc');

        $this->assertStringStartsWith('%PDF', (string) $pdf);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_each_send_gets_a_freshly_generated_pdf(): void
    {
        $service = app(InvoicePdfService::class);

        $first = $service->generate($this->invoice(), 'https://simphub.io/pay/abc');
        sleep(1); // dompdf stamps the creation time, so a fresh render differs
        $second = $service->generate($this->invoice(), 'https://simphub.io/pay/abc');

        $this->assertNotSame($first, $second);
    }
}
