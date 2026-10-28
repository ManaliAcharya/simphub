<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Outbound\DTOs\AchStatus;
use Modules\Outbound\Services\PayaAchStatusService;
use Modules\Payment\Mail\AchReturnedNotificationMail;
use Modules\Payment\Services\AchSettlementService;
use RuntimeException;
use Tests\TestCase;

class AchSettlementTest extends TestCase
{
    private const MATTER_UUID = '34b58a90-b8be-11f1-9433-6583f0484da9';

    private function configurePaya(bool $on): void
    {
        config([
            'services.paya.status_wsdl'   => $on ? 'https://demo.example.test/Reporting.asmx?WSDL' : null,
            'services.paya.status_method' => $on ? 'ViewSingleRecord' : null,
        ]);
    }

    private function transaction(array $attributes = []): Transaction
    {
        return (new Transaction)->forceFill(array_merge([
            'gateway'           => 'paya',
            'status'            => 'CAPTURED',
            'settlement_status' => AchStatus::PENDING,
            'fund_type'         => 'TRUST',
            'amount_cents'      => 50000,
            'fee_cents'         => 0,
            'gateway_txn_id'    => '1234567',
        ], $attributes));
    }

    public function test_settled_response(): void
    {
        $status = app(PayaAchStatusService::class)->parseResponse(
            '<RESPONSE><TRANSACTION><TRANSACTION_ID>1234567</TRANSACTION_ID><STATUS>Settled</STATUS></TRANSACTION></RESPONSE>'
        );

        $this->assertSame(AchStatus::SETTLED, $status->status);
    }

    public function test_returned_response_carries_the_return_code_and_reason(): void
    {
        $status = app(PayaAchStatusService::class)->parseResponse(
            '<RESPONSE><TRANSACTION><STATUS>Returned</STATUS><RETURN_CODE>r01</RETURN_CODE>'
            .'<RETURN_REASON>Insufficient Funds</RETURN_REASON></TRANSACTION></RESPONSE>'
        );

        $this->assertSame(AchStatus::RETURNED, $status->status);
        $this->assertSame('R01', $status->returnCode);
        $this->assertSame('Insufficient Funds', $status->returnReason);
    }

    public function test_a_return_code_alone_means_returned(): void
    {
        $status = app(PayaAchStatusService::class)->parseResponse(
            '<RESPONSE><STATUS>Processed</STATUS><RETURN_CODE>R10</RETURN_CODE></RESPONSE>'
        );

        $this->assertSame(AchStatus::RETURNED, $status->status);
    }

    public function test_anything_else_is_still_pending(): void
    {
        $status = app(PayaAchStatusService::class)->parseResponse('<RESPONSE><STATUS>Open</STATUS></RESPONSE>');

        $this->assertSame(AchStatus::PENDING, $status->status);
    }

    public function test_a_gateway_exception_is_an_error_not_a_status(): void
    {
        $this->expectException(RuntimeException::class);

        app(PayaAchStatusService::class)->parseResponse('<RESPONSE><EXCEPTION><MESSAGE>Invalid login</MESSAGE></EXCEPTION></RESPONSE>');
    }

    public function test_firm_notification_is_held_only_when_paya_lookup_is_configured(): void
    {
        $settlements = app(AchSettlementService::class);

        $this->configurePaya(false);
        $this->assertFalse($settlements->holdsMerchantNotification($this->transaction()));

        $this->configurePaya(true);
        $this->assertTrue($settlements->holdsMerchantNotification($this->transaction()));
        $this->assertFalse($settlements->holdsMerchantNotification($this->transaction(['settlement_status' => AchStatus::SETTLED])));
        $this->assertFalse($settlements->holdsMerchantNotification($this->transaction(['gateway' => 'fluidpay', 'settlement_status' => null])));
    }

    public function test_only_lawcus_clients_with_the_setting_on_track_ach_settlement(): void
    {
        $client = fn (string $pms, bool $on) => (new Client)->forceFill([
            'client_pms'                   => $pms,
            'lawcus_ach_settlement_notify' => $on,
        ]);

        $this->assertTrue($client('LAWCUS', true)->tracksAchSettlement('Paya'));
        $this->assertFalse($client('LAWCUS', false)->tracksAchSettlement('paya'));
        $this->assertFalse($client('LAWCUS', true)->tracksAchSettlement('fluidpay'));
        $this->assertFalse($client('QUICKBOOKS', true)->tracksAchSettlement('paya'));
    }

    public function test_poll_does_nothing_until_paya_lookup_is_configured(): void
    {
        $this->configurePaya(false);

        $this->assertSame(
            ['checked' => 0, 'settled' => 0, 'returned' => 0, 'failed' => 0],
            app(AchSettlementService::class)->pollPending(),
        );
    }

    public function test_returned_trust_payment_email_asks_the_firm_to_reverse_it_in_lawcus(): void
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'     => 'lawcus',
            'invoice_number' => 'TR-42',
            'amount_cents'   => 50000,
            'currency'       => 'USD',
            'customer'       => ['name' => 'Test Client'],
            'raw_payload'    => ['invoice' => ['data' => ['matter_uuid' => self::MATTER_UUID]]],
        ]);

        $transaction = $this->transaction([
            'return_code'   => 'R01',
            'return_reason' => 'Insufficient Funds',
            'created_at'    => Carbon::parse('2026-10-20'),
            'returned_at'   => Carbon::parse('2026-10-23'),
        ]);

        $mail = new AchReturnedNotificationMail($transaction, $invoice);
        $html = $mail->render();

        $this->assertStringContainsString('ACH payment returned', $mail->envelope()->subject);
        $this->assertStringContainsString('Trust request', $html);
        $this->assertStringContainsString('IOLTA (trust) account', $html);
        $this->assertStringContainsString('reverse this payment in Lawcus', $html);
        $this->assertStringContainsString('R01 – Insufficient Funds', $html);
        $this->assertStringContainsString('10/23/2026', $html);
        $this->assertStringContainsString('https://app.lawcus.com/matter/'.self::MATTER_UUID, $html);
    }

    public function test_returned_operating_payment_email_names_the_operating_account(): void
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'     => 'lawcus',
            'invoice_number' => '77',
            'amount_cents'   => 50000,
            'currency'       => 'USD',
        ]);

        $html = (new AchReturnedNotificationMail($this->transaction(['fund_type' => 'OPERATING']), $invoice))->render();

        $this->assertStringContainsString('Invoice #77', $html);
        $this->assertStringContainsString('Operating account', $html);
        $this->assertStringNotContainsString('IOLTA', $html);
    }
}
