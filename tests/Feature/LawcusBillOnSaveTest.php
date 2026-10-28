<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Modules\Inbound\Jobs\PollLawcusInvoicesJob;
use Tests\TestCase;

/** Lawcus invoices are picked up as soon as they're saved, without waiting for the firm to send them. */
class LawcusBillOnSaveTest extends TestCase
{
    private function since(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-28 00:00:00');
    }

    /** A saved, unsent invoice as /v2/invoices returns it when the firm doesn't use approvals. */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'         => '8cb0d620-d2d0-11f1-9d20-8d53264bd886',
            'type'       => 'DEFAULT',
            'issue_date' => '2026-10-28 13:07:26',
            'status'     => 'APPROVED',
            'sent_at'    => null,
            'amount_due' => '8.00',
        ], $overrides);
    }

    public function test_saved_invoice_is_picked_up_before_it_is_sent(): void
    {
        $this->assertTrue(PollLawcusInvoicesJob::shouldIngest($this->row(), $this->since()));
        $this->assertTrue(PollLawcusInvoicesJob::shouldIngest($this->row(['type' => 'TRUST']), $this->since()));
    }

    public function test_sent_invoice_is_still_picked_up(): void
    {
        $sent = $this->row(['status' => 'SENT', 'sent_at' => '2026-10-28 13:08:00']);

        $this->assertTrue(PollLawcusInvoicesJob::shouldIngest($sent, $this->since()));
    }

    public function test_invoices_still_waiting_on_an_approver_are_never_picked_up(): void
    {
        foreach (['DRAFT', 'PENDING_APPROVAL', 'REJECTED'] as $status) {
            $this->assertFalse(PollLawcusInvoicesJob::shouldIngest($this->row(['status' => $status]), $this->since()), $status);
        }
    }

    public function test_older_and_voided_invoices_are_never_picked_up(): void
    {
        $this->assertFalse(PollLawcusInvoicesJob::shouldIngest($this->row(['issue_date' => '2026-10-27 23:00:00']), $this->since()));
        $this->assertFalse(PollLawcusInvoicesJob::shouldIngest($this->row(['status' => 'VOID']), $this->since()));
    }

    public function test_saved_invoice_with_nothing_due_is_recorded(): void
    {
        $this->assertTrue(PollLawcusInvoicesJob::shouldIngest($this->row(['status' => 'PAID', 'amount_due' => '0.00']), $this->since()));
    }
}
