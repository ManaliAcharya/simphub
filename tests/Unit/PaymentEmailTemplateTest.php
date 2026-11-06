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

    public function test_blank_flat_fee_fields_use_the_billable_template(): void
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
}
