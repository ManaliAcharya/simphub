<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Billing\Models\Invoice;
use Modules\Payment\Services\InvoicePdfService;
use ReflectionMethod;
use Tests\TestCase;

class InvoicePdfDataTest extends TestCase
{
    private function invokePdf(string $method, Invoice $invoice): mixed
    {
        $reflection = new ReflectionMethod(InvoicePdfService::class, $method);

        return $reflection->invoke(app(InvoicePdfService::class), $invoice);
    }

    private function qbInvoice(array $qbInvoice, array $attributes = []): Invoice
    {
        return (new Invoice)->forceFill([
            'pms_source'     => 'quickbooks',
            'invoice_number' => '1051',
            'amount_cents'   => 300,
            'raw_payload'    => ['invoice' => ['Invoice' => $qbInvoice]],
        ] + $attributes);
    }

    private function salesLine(string $item, string $description, float $qty, float $rate): array
    {
        return [
            'DetailType'          => 'SalesItemLineDetail',
            'Amount'              => $qty * $rate,
            'Description'         => $description,
            'SalesItemLineDetail' => ['ItemRef' => ['name' => $item], 'Qty' => $qty, 'UnitPrice' => $rate],
        ];
    }

    public function test_quickbooks_items_are_listed(): void
    {
        $items = $this->invokePdf('resolveLineItems', $this->qbInvoice(['Line' => [
            $this->salesLine('Pool cleaning', 'Weekly service', 2, 1.5),
            ['DetailType' => 'SubTotalLineDetail', 'Amount' => 3],
        ]]));

        $this->assertCount(1, $items);
        $this->assertSame('Pool cleaning', $items[0]['productOrService']);
        $this->assertSame('Weekly service', $items[0]['description']);
        $this->assertSame(2.0, $items[0]['qty']);
    }

    public function test_quickbooks_bundle_lists_its_items(): void
    {
        $items = $this->invokePdf('resolveLineItems', $this->qbInvoice(['Line' => [
            [
                'DetailType'      => 'GroupLineDetail',
                'Amount'          => 3,
                'GroupLineDetail' => ['GroupItemRef' => ['name' => 'Starter bundle'], 'Line' => [
                    $this->salesLine('Setup', 'One-time setup', 1, 2),
                    $this->salesLine('Support', 'First month', 1, 1),
                ]],
            ],
            ['DetailType' => 'SubTotalLineDetail', 'Amount' => 3],
        ]]));

        $this->assertSame(['Setup', 'Support'], array_column($items, 'productOrService'));
        $this->assertNotSame('Invoice #1051', $items[0]['description']);
    }

    public function test_invoice_date_comes_from_quickbooks_not_from_when_simphub_received_it(): void
    {
        $invoice = $this->qbInvoice(['TxnDate' => '2026-10-22', 'DueDate' => '2026-10-22'], [
            'created_at' => Carbon::parse('2026-10-23 09:00:00'),
        ]);

        $this->assertSame('October 22, 2026', $this->invokePdf('resolveIssueDate', $invoice));
    }

    public function test_invoice_date_falls_back_to_when_simphub_received_it(): void
    {
        $invoice = $this->qbInvoice([], ['created_at' => Carbon::parse('2026-10-23 09:00:00')]);

        $this->assertSame('October 23, 2026', $this->invokePdf('resolveIssueDate', $invoice));
    }
}
