<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Payment\Mail\MerchantPaymentNotificationMail;
use Tests\TestCase;

class MerchantNotificationLawcusMatterTest extends TestCase
{
    private const MATTER_UUID = '34b58a90-b8be-11f1-9433-6583f0484da9';

    private function render(string $pmsSource, array $invoiceData, string $fundType = 'OPERATING', ?Client $client = null, ?string $syncStatus = 'SYNCED', ?string $externalInvoiceId = null): string
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'      => $pmsSource,
            'external_invoice_id' => $externalInvoiceId,
            'fund_type'       => $fundType,
            'pms_sync_status' => $syncStatus,
            'invoice_number' => '123',
            'amount_cents'   => 90000,
            'currency'       => 'USD',
            'customer'       => ['name' => 'Test Client', 'email' => 'client@example.com'],
            'raw_payload'    => ['invoice' => ['data' => $invoiceData]],
        ]);

        $transaction = (new Transaction)->forceFill([
            'gateway'      => 'fluidpay',
            'amount_cents' => 90000,
            'fee_cents'    => 0,
        ]);

        return (new MerchantPaymentNotificationMail($transaction, $invoice, $client))->render();
    }

    public function test_lawcus_invoice_links_to_its_matter_by_uuid(): void
    {
        $html = $this->render('lawcus', ['matter_id' => 1949408, 'matter_uuid' => self::MATTER_UUID]);

        $this->assertStringContainsString('Open matter in Lawcus', $html);
        $this->assertStringContainsString('https://app.lawcus.com/matter/'.self::MATTER_UUID, $html);
    }

    public function test_falls_back_to_the_embedded_matter_uuid(): void
    {
        $html = $this->render('lawcus', ['matter' => ['id' => 1949408, 'uuid' => self::MATTER_UUID]]);

        $this->assertStringContainsString('https://app.lawcus.com/matter/'.self::MATTER_UUID, $html);
    }

    public function test_no_button_when_the_invoice_has_no_matter(): void
    {
        $this->assertStringNotContainsString('Open matter in Lawcus', $this->render('lawcus', []));
    }

    public function test_lawcus_shows_the_account_the_payment_went_to(): void
    {
        $client = (new Client)->forceFill([
            'client_name'                      => 'RR Law',
            'lawcus_default_bank_account_name' => 'Chase Operating',
            'lawcus_trust_bank_account_name'   => 'IOLTA Trust',
        ]);

        $operating = $this->render('lawcus', [], client: $client);
        $this->assertStringContainsString('Deposited to', $operating);
        $this->assertStringContainsString('Operating: Chase Operating', $operating);

        $trust = $this->render('lawcus', [], 'TRUST', client: $client);
        $this->assertStringContainsString('Trust (IOLTA): IOLTA Trust', $trust);

        $this->assertStringContainsString('Trust (IOLTA)', $this->render('lawcus', [], 'TRUST'));
    }

    public function test_lawcus_says_marked_paid_only_when_the_recording_was_confirmed(): void
    {
        $this->assertStringContainsString('automatically marked as paid in Lawcus', $this->render('lawcus', [], syncStatus: 'SYNCED'));

        $failed = $this->render('lawcus', [], syncStatus: 'FAILED');
        $this->assertStringNotContainsString('automatically marked as paid', $failed);
        $this->assertStringContainsString("hasn't been recorded in Lawcus automatically", $failed);
    }

    public function test_other_pms_emails_have_no_account_row_and_keep_the_paid_banner(): void
    {
        $html = $this->render('quickbooks', [], syncStatus: null);

        $this->assertStringNotContainsString('Deposited to', $html);
        $this->assertStringContainsString('automatically marked as paid', $html);
    }

    public function test_clio_never_claims_marked_paid_and_links_to_the_bill(): void
    {
        foreach (['SYNCED', 'FAILED', null] as $status) {
            $html = $this->render('clio', [], syncStatus: $status, externalInvoiceId: '1249095803');

            $this->assertStringNotContainsString('automatically marked as paid', $html);
            $this->assertStringContainsString('this payment has not been recorded in Clio', $html);
            $this->assertStringContainsString('Open bill in Clio', $html);
            $this->assertStringContainsString('https://app.clio.com/nc/#/bills/1249095803', $html);
        }
    }

    public function test_other_pms_emails_are_unchanged(): void
    {
        foreach (['clio', 'quickbooks', 'zoho', 'wave', 'custom'] as $pms) {
            $html = $this->render($pms, ['matter_uuid' => self::MATTER_UUID]);

            $this->assertStringNotContainsString('Open matter in Lawcus', $html, $pms);
        }
    }
}
