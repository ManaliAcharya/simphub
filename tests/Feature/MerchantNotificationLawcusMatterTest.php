<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Payment\Mail\MerchantPaymentNotificationMail;
use Tests\TestCase;

class MerchantNotificationLawcusMatterTest extends TestCase
{
    private const MATTER_UUID = '34b58a90-b8be-11f1-9433-6583f0484da9';

    private function render(string $pmsSource, array $invoiceData): string
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'     => $pmsSource,
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

        return (new MerchantPaymentNotificationMail($transaction, $invoice))->render();
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

    public function test_other_pms_emails_are_unchanged(): void
    {
        foreach (['clio', 'quickbooks', 'zoho', 'wave', 'custom'] as $pms) {
            $html = $this->render($pms, ['matter_uuid' => self::MATTER_UUID]);

            $this->assertStringNotContainsString('Open matter in Lawcus', $html, $pms);
        }
    }
}
