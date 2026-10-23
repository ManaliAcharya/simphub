<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Payment\Services\InvoicePdfService;
use Tests\TestCase;

class InvoicePdfReuseTest extends TestCase
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

    public function test_pdf_is_generated_once_and_reused_for_later_sends(): void
    {
        Storage::fake('local');
        $service = app(InvoicePdfService::class);

        $first = $service->generate($this->invoice(), 'https://simphub.io/pay/abc');
        sleep(1); // dompdf stamps the creation time, so a re-render would differ
        $second = $service->generate($this->invoice(), 'https://simphub.io/pay/abc');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertCount(1, Storage::disk('local')->allFiles('invoice-pdfs'));
    }

    public function test_pdf_is_regenerated_when_the_invoice_changes(): void
    {
        Storage::fake('local');
        $service = app(InvoicePdfService::class);

        $original = $service->generate($this->invoice(14585), 'https://simphub.io/pay/abc');
        sleep(1);
        $changed = $service->generate($this->invoice(20000), 'https://simphub.io/pay/abc');

        $this->assertNotSame($original, $changed);
        $this->assertCount(1, Storage::disk('local')->allFiles('invoice-pdfs'), 'the outdated version is removed');
    }

    public function test_pdf_is_regenerated_for_a_new_payment_link(): void
    {
        Storage::fake('local');
        $service = app(InvoicePdfService::class);

        $original = $service->generate($this->invoice(), 'https://simphub.io/pay/abc');
        sleep(1);
        $newLink = $service->generate($this->invoice(), 'https://simphub.io/pay/xyz');

        $this->assertNotSame($original, $newLink);
    }
}
