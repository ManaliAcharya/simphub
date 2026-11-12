<?php

namespace Tests\Unit;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Payment\Services\PaymentEmailTemplate;
use Tests\TestCase;

class PaymentEmailTemplateTest extends TestCase
{
    private function invoice(string $pms, string $fundType = 'OPERATING', array $raw = []): Invoice
    {
        return new Invoice(['pms_source' => $pms, 'fund_type' => $fundType, 'raw_payload' => $raw]);
    }

    private function config(array $templates = []): EmailConfiguration
    {
        return new EmailConfiguration([
            'subject_template'         => 'Invoice #{invoice_number}',
            'subject_template_updated' => 'Updated: Invoice #{invoice_number}',
            'body_header'              => 'Billable header',
            'body_footer'              => 'Billable footer',
            'attach_pdf'               => true,
            'templates'                => $templates,
        ]);
    }

    public function test_trust_requests_get_the_retainer_template(): void
    {
        $this->assertSame(PaymentEmailTemplate::RETAINER, PaymentEmailTemplate::kindFor($this->invoice('lawcus', 'TRUST')));
        $this->assertSame(PaymentEmailTemplate::RETAINER, PaymentEmailTemplate::kindFor($this->invoice('clio', 'TRUST')));
    }

    public function test_invoices_pick_billable_or_flat_fee_by_the_matter_billing_type(): void
    {
        $flat   = $this->invoice('clio', 'OPERATING', ['matter' => ['data' => ['billing_method' => 'flat']]]);
        $hourly = $this->invoice('clio', 'OPERATING', ['matter' => ['data' => ['billing_method' => 'hourly']]]);
        $lawcusFlat = $this->invoice('lawcus', 'OPERATING', ['invoice' => ['data' => ['matter' => ['billing_type' => 'FLAT_FEE']]]]);

        $this->assertSame(PaymentEmailTemplate::FLAT_FEE, PaymentEmailTemplate::kindFor($flat));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($hourly));
        $this->assertSame(PaymentEmailTemplate::FLAT_FEE, PaymentEmailTemplate::kindFor($lawcusFlat));
        // Unknown billing type → billable.
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($this->invoice('lawcus')));
    }

    /** Line item mixes seen on real Lawcus test invoices (#00030–#00036). */
    public function test_lawcus_invoices_pick_billable_or_flat_fee_by_their_line_items(): void
    {
        $lawcus = fn (array $lines) => $this->invoice('lawcus', 'OPERATING', ['invoice' => ['data' => $lines + [
            'timeentries' => [],
            'flatfees'    => [],
            'expenses'    => [],
            // Lawcus reports HOURLY even on flat fee matters; line items win.
            'matter'      => ['billing_type' => 'HOURLY'],
        ]]]);
        $time    = ['id' => 't1', 'quantity' => '2.00', 'rate' => '3.00', 'is_non_billable' => null];
        $flatFee = ['id' => 'f1', 'quantity' => 1, 'rate' => '22.00', 'is_non_billable' => null];
        $expense = ['id' => 'e1', 'quantity' => '1.00', 'rate' => '5.00', 'is_non_billable' => null];

        $this->assertSame(PaymentEmailTemplate::FLAT_FEE, PaymentEmailTemplate::kindFor($lawcus(['flatfees' => [$flatFee]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus(['timeentries' => [$time]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus(['expenses' => [$expense]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus(['timeentries' => [$time], 'flatfees' => [$flatFee]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus(['flatfees' => [$flatFee], 'expenses' => [$expense]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus(['timeentries' => [$time], 'expenses' => [$expense]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus(['timeentries' => [$time], 'flatfees' => [$flatFee], 'expenses' => [$expense]])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($lawcus([])));

        // A non-billable time entry isn't charged, so a flat fee invoice stays flat fee.
        $this->assertSame(PaymentEmailTemplate::FLAT_FEE, PaymentEmailTemplate::kindFor($lawcus([
            'timeentries' => [['is_non_billable' => true] + $time],
            'flatfees'    => [$flatFee],
        ])));

        // Trust Requests stay retainer requests whatever their lines.
        $this->assertSame(PaymentEmailTemplate::RETAINER, PaymentEmailTemplate::kindFor($this->invoice('lawcus', 'TRUST', [
            'invoice' => ['data' => ['flatfees' => [$flatFee]]],
        ])));
    }

    public function test_other_pms_always_use_the_billable_template(): void
    {
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($this->invoice('quickbooks', 'TRUST')));
    }

    public function test_retainer_never_falls_back_to_invoice_wording(): void
    {
        $template = PaymentEmailTemplate::resolve($this->invoice('lawcus', 'TRUST'), $this->config());

        $this->assertSame(PaymentEmailTemplate::RETAINER_SUBJECT, $template['subject']);
        $this->assertNull($template['body_header']);
        $this->assertFalse($template['attach_pdf']);

        $this->assertSame(PaymentEmailTemplate::RETAINER_SUBJECT, PaymentEmailTemplate::resolve($this->invoice('lawcus', 'TRUST'), null)['subject']);
    }

    public function test_reminder_subject_follows_the_template(): void
    {
        $clientReminder = 'Reminder: Invoice {invoice_number}';

        $this->assertSame(PaymentEmailTemplate::RETAINER_REMINDER_SUBJECT,
            PaymentEmailTemplate::resolve($this->invoice('lawcus', 'TRUST'), $this->config(), $clientReminder)['reminder_subject']);
        $this->assertSame('Deposit still needed #{invoice_number}',
            PaymentEmailTemplate::resolve($this->invoice('clio', 'TRUST'), $this->config([
                PaymentEmailTemplate::RETAINER => ['reminder_subject' => 'Deposit still needed #{invoice_number}'],
            ]), $clientReminder)['reminder_subject']);
        $this->assertSame($clientReminder,
            PaymentEmailTemplate::resolve($this->invoice('quickbooks'), $this->config(), $clientReminder)['reminder_subject']);
    }

    public function test_blank_flat_fee_fields_use_the_base_settings(): void
    {
        $invoice  = $this->invoice('clio', 'OPERATING', ['matter' => ['data' => ['billing_method' => 'flat']]]);
        $template = PaymentEmailTemplate::resolve($invoice, $this->config([
            PaymentEmailTemplate::FLAT_FEE => ['subject' => 'Flat fee #{invoice_number}', 'body_header' => null, 'attach_pdf' => false],
        ]));

        $this->assertSame('Flat fee #{invoice_number}', $template['subject']);
        $this->assertSame('Billable header', $template['body_header']);
        $this->assertSame('Updated: Invoice #{invoice_number}', $template['subject_updated']);
        $this->assertFalse($template['attach_pdf']);
    }

    public function test_billable_template_is_the_existing_email_configuration(): void
    {
        $template = PaymentEmailTemplate::resolve($this->invoice('quickbooks'), $this->config());

        $this->assertSame('Invoice #{invoice_number}', $template['subject']);
        $this->assertSame('Billable footer', $template['body_footer']);
        $this->assertTrue($template['attach_pdf']);
    }

    public function test_law_firms_billable_hours_template_overrides_the_base_settings(): void
    {
        $config = $this->config([
            PaymentEmailTemplate::BILLABLE => [
                'subject'          => 'Hours billed #{invoice_number}',
                'reminder_subject' => 'Hours still due {invoice_number}',
                'body_header'      => 'Thank you for the hours this month.',
                'body_footer'      => null,
                'attach_pdf'       => false,
            ],
        ]);
        $hourly = $this->invoice('lawcus', 'OPERATING', ['invoice' => ['data' => ['timeentries' => [['id' => 't1']], 'flatfees' => [], 'expenses' => []]]]);

        $template = PaymentEmailTemplate::resolve($hourly, $config, 'Reminder: Invoice {invoice_number}');

        $this->assertSame(PaymentEmailTemplate::BILLABLE, $template['kind']);
        $this->assertSame('Hours billed #{invoice_number}', $template['subject']);
        $this->assertSame('Hours still due {invoice_number}', $template['reminder_subject']);
        $this->assertSame('Thank you for the hours this month.', $template['body_header']);
        // Blank fields use the base settings.
        $this->assertSame('Updated: Invoice #{invoice_number}', $template['subject_updated']);
        $this->assertSame('Billable footer', $template['body_footer']);
        $this->assertFalse($template['attach_pdf']);

        // Flat fee invoices never pick up the billable hours wording.
        $flat = $this->invoice('lawcus', 'OPERATING', ['invoice' => ['data' => ['timeentries' => [], 'flatfees' => [['id' => 'f1']], 'expenses' => []]]]);
        $this->assertSame('Invoice #{invoice_number}', PaymentEmailTemplate::resolve($flat, $config)['subject']);
        $this->assertSame('Billable header', PaymentEmailTemplate::resolve($flat, $config)['body_header']);

        // Other PMS have no per-trigger templates.
        $this->assertSame('Invoice #{invoice_number}', PaymentEmailTemplate::resolve($this->invoice('quickbooks'), $config)['subject']);
    }

    public function test_law_firms_without_a_saved_billable_hours_template_keep_the_base_settings(): void
    {
        $template = PaymentEmailTemplate::resolve($this->invoice('clio'), $this->config());

        $this->assertSame('Invoice #{invoice_number}', $template['subject']);
        $this->assertSame('Billable header', $template['body_header']);
        $this->assertTrue($template['attach_pdf']);
    }
}
