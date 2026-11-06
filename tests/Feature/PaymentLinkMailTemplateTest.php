<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Payment\Mail\PaymentLinkMail;
use Modules\Payment\Mail\PaymentReminderMail;
use Modules\Payment\Services\PaymentEmailTemplate;
use Tests\TestCase;

class PaymentLinkMailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function mail(string $fundType, ?EmailConfiguration $config): PaymentLinkMail
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => 'firm-1',
            'external_invoice_id' => 'TR-7',
            'invoice_number'      => '1007',
            'amount_cents'        => 250000,
            'currency'            => 'USD',
            'fund_type'           => $fundType,
        ]);

        return new PaymentLinkMail($invoice, new PaymentSession, 'https://pay.example.test/s/abc', '%PDF-fake', false, $config);
    }

    public function test_trust_request_email_reads_as_a_retainer_deposit_request(): void
    {
        $config = new EmailConfiguration([
            'subject_template' => 'Invoice #{invoice_number}',
            'body_header'      => 'Invoice header',
            'attach_pdf'       => true,
            'templates'        => [PaymentEmailTemplate::RETAINER => [
                'subject'     => 'Retainer deposit for #{invoice_number}',
                'body_footer' => 'Pay by wire to the trust account, or by check.',
            ]],
        ]);

        $mail = $this->mail('TRUST', $config);

        $mail->assertHasSubject('Retainer deposit for #1007');
        $mail->assertSeeInHtml('Retainer deposit request');
        $mail->assertSeeInHtml('Deposit Amount');
        $mail->assertSeeInHtml('Pay by wire to the trust account, or by check.');
        $mail->assertDontSeeInHtml('Invoice ready for payment');
        $mail->assertDontSeeInHtml('Invoice header');
        $this->assertSame([], $mail->attachments());
    }

    public function test_trust_request_reminder_asks_for_the_deposit(): void
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source' => 'clio', 'pms_client_id' => 'firm-1', 'external_invoice_id' => 'TR-8',
            'invoice_number' => '1008', 'amount_cents' => 100000, 'currency' => 'USD', 'fund_type' => 'TRUST',
        ]);

        $mail = new PaymentReminderMail($invoice, new PaymentSession, 'https://pay.example.test/s/abc',
            PaymentEmailTemplate::RETAINER_REMINDER_SUBJECT, '%PDF-fake', null);

        $mail->assertHasSubject('Reminder: Retainer Request 1008 is awaiting your deposit');
        $mail->assertSeeInHtml('Retainer Request #1008');
        $mail->assertSeeInHtml('awaiting your deposit');
        $this->assertSame([], $mail->attachments());
    }

    public function test_regular_invoice_email_is_unchanged(): void
    {
        $config = new EmailConfiguration([
            'subject_template' => 'Invoice #{invoice_number}',
            'body_header'      => 'Invoice header',
            'attach_pdf'       => true,
        ]);

        $mail = $this->mail('OPERATING', $config);

        $mail->assertHasSubject('Invoice #1007');
        $mail->assertSeeInHtml('Invoice ready for payment');
        $mail->assertSeeInHtml('Invoice header');
        $this->assertCount(1, $mail->attachments());
    }
}
