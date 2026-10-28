<?php

namespace Tests\Feature;

use Modules\Inbound\Services\LawcusInvoiceIngestionService;
use Tests\TestCase;

/** Lawcus invoices with nothing due (e.g. paid from the matter's trust balance) are recorded, never emailed. */
class LawcusNothingDueTest extends TestCase
{
    public function test_paid_or_zero_due_invoices_have_nothing_due(): void
    {
        $this->assertTrue(LawcusInvoiceIngestionService::hasNothingDue(['status' => 'paid', 'amount_due' => '16.00']));
        $this->assertTrue(LawcusInvoiceIngestionService::hasNothingDue(['status' => 'SENT', 'amount_due' => '0.00']));
        $this->assertTrue(LawcusInvoiceIngestionService::hasNothingDue(['amount_due' => 0]));
    }

    public function test_invoices_still_owed_or_without_an_amount_due_are_billed(): void
    {
        $this->assertFalse(LawcusInvoiceIngestionService::hasNothingDue(['status' => 'SENT', 'amount_due' => '16.00']));
        $this->assertFalse(LawcusInvoiceIngestionService::hasNothingDue(['status' => 'PARTIALLY_PAID', 'amount_due' => '4.00']));
        $this->assertFalse(LawcusInvoiceIngestionService::hasNothingDue(['status' => 'SENT']));
        $this->assertFalse(LawcusInvoiceIngestionService::hasNothingDue([]));
    }
}
