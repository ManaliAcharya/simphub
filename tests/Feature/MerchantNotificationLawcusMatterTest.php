<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Payment\Mail\MerchantPaymentNotificationMail;
use Tests\TestCase;

class MerchantNotificationLawcusMatterTest extends TestCase
{
    private function render(string $pmsSource, ?string $matterId): string
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'         => $pmsSource,
            'invoice_number'     => '123',
            'external_matter_id' => $matterId,
            'amount_cents'       => 90000,
            'currency'           => 'USD',
            'customer'           => ['name' => 'Test Client', 'email' => 'client@example.com'],
        ]);

        $transaction = (new Transaction)->forceFill([
            'gateway'      => 'fluidpay',
            'amount_cents' => 90000,
            'fee_cents'    => 0,
        ]);

        return (new MerchantPaymentNotificationMail($transaction, $invoice))->render();
    }

    public function test_lawcus_invoice_links_to_its_matter(): void
    {
        config(['services.lawcus.matter_url' => 'https://app.lawcus.com/matters/{matter_id}']);

        $html = $this->render('lawcus', '218149');

        $this->assertStringContainsString('Open matter in Lawcus', $html);
        $this->assertStringContainsString('https://app.lawcus.com/matters/218149', $html);
    }

    public function test_no_button_without_a_configured_url_or_a_matter(): void
    {
        config(['services.lawcus.matter_url' => null]);
        $this->assertStringNotContainsString('Open matter in Lawcus', $this->render('lawcus', '218149'));

        config(['services.lawcus.matter_url' => 'https://app.lawcus.com/matters/{matter_id}']);
        $this->assertStringNotContainsString('Open matter in Lawcus', $this->render('lawcus', null));
    }

    public function test_other_pms_emails_are_unchanged(): void
    {
        config(['services.lawcus.matter_url' => 'https://app.lawcus.com/matters/{matter_id}']);

        foreach (['clio', 'quickbooks', 'zoho', 'wave', 'custom'] as $pms) {
            $this->assertStringNotContainsString('Open matter in Lawcus', $this->render($pms, '218149'), $pms);
        }
    }
}
