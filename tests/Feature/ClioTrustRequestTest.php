<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Http\Controllers\PmsIntegrationController;
use Modules\Inbound\Jobs\RenewClioWebhooksJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\ClioInvoiceIngestionService;
use Modules\Inbound\Services\ClioTrustPaymentSyncService;
use Tests\TestCase;

/** Clio Trust Requests: bills of kind trust, paid to the IOLTA MID and deposited to the trust account. */
class ClioTrustRequestTest extends TestCase
{
    public function test_trust_request_is_told_apart_by_the_bill_kind(): void
    {
        $this->assertTrue(ClioInvoiceIngestionService::isTrustKind(['kind' => 'trust_kind']));
        $this->assertTrue(ClioInvoiceIngestionService::isTrustKind(['kind' => 'Trust']));
        $this->assertFalse(ClioInvoiceIngestionService::isTrustKind(['kind' => 'revenue_kind']));
    }

    public function test_missing_kind_is_left_for_clio_to_answer(): void
    {
        $this->assertNull(ClioInvoiceIngestionService::isTrustKind([]));
        $this->assertNull(ClioInvoiceIngestionService::isTrustKind(['kind' => '']));
    }

    public function test_matter_is_set_only_when_the_bill_is_for_one(): void
    {
        $this->assertSame('77', ClioInvoiceIngestionService::singleMatterId(['matters' => [['id' => 77]]]));
        $this->assertNull(ClioInvoiceIngestionService::singleMatterId(['matters' => [['id' => 77], ['id' => 78]]]));
        $this->assertNull(ClioInvoiceIngestionService::singleMatterId(['matters' => []]));
    }

    public function test_clio_clients_need_an_iolta_mid_for_trust_money(): void
    {
        $this->assertTrue((new Client)->forceFill(['client_pms' => 'CLIO'])->requiresIoltaMid());
        $this->assertTrue((new Client)->forceFill(['client_pms' => 'LAWCUS'])->requiresIoltaMid());
        $this->assertFalse((new Client)->forceFill(['client_pms' => 'ZOHO'])->requiresIoltaMid());
    }

    public function test_only_clio_trust_accounts_count_as_trust(): void
    {
        $this->assertTrue(PmsIntegrationController::isClioTrustAccount(['account_type' => 'TrustAccount']));
        $this->assertFalse(PmsIntegrationController::isClioTrustAccount(['account_type' => 'OperatingAccount']));
        $this->assertFalse(PmsIntegrationController::isClioTrustAccount([]));
    }

    public function test_single_matter_trust_request_is_deposited_to_that_matter(): void
    {
        $deposits = ClioTrustPaymentSyncService::allocate(500.00, [
            ['total' => 500.00, 'matter' => ['id' => 77]],
        ], null);

        $this->assertSame([['matter_id' => '77', 'amount' => 500.0]], $deposits);
    }

    public function test_multi_matter_trust_request_is_split_per_matter_to_the_cent(): void
    {
        $deposits = ClioTrustPaymentSyncService::allocate(100.00, [
            ['total' => 33.33, 'matter' => ['id' => 1]],
            ['total' => 33.33, 'matter' => ['id' => 2]],
            ['total' => 33.34, 'matter' => ['id' => 3]],
        ], null);

        $this->assertSame([
            ['matter_id' => '1', 'amount' => 33.33],
            ['matter_id' => '2', 'amount' => 33.33],
            ['matter_id' => '3', 'amount' => 33.34],
        ], $deposits);
        $this->assertSame(10000, (int) round(array_sum(array_column($deposits, 'amount')) * 100));
    }

    public function test_partial_payment_is_split_in_proportion_and_sums_exactly(): void
    {
        $deposits = ClioTrustPaymentSyncService::allocate(10.00, [
            ['total' => 20.00, 'matter' => ['id' => 1]],
            ['total' => 10.00, 'matter' => ['id' => 2]],
        ], null);

        $this->assertSame([
            ['matter_id' => '1', 'amount' => 6.66],
            ['matter_id' => '2', 'amount' => 3.34],
        ], $deposits);
    }

    public function test_without_line_items_the_deposit_goes_to_the_bill_matter_or_client_ledger(): void
    {
        $this->assertSame([['matter_id' => '9', 'amount' => 25.5]], ClioTrustPaymentSyncService::allocate(25.50, [], '9'));
        $this->assertSame([['matter_id' => null, 'amount' => 25.5]], ClioTrustPaymentSyncService::allocate(25.50, [], null));
        $this->assertSame([['matter_id' => null, 'amount' => 25.5]], ClioTrustPaymentSyncService::allocate(25.50, [
            ['total' => 25.50, 'matter' => null],
        ], '9'));
    }

    public function test_nothing_is_deposited_for_a_zero_amount(): void
    {
        $this->assertSame([], ClioTrustPaymentSyncService::allocate(0.0, [['total' => 10, 'matter' => ['id' => 1]]], null));
    }

    public function test_deposit_description_is_stable_for_duplicate_detection(): void
    {
        $invoice = (new Invoice)->forceFill(['invoice_number' => '1042', 'external_invoice_id' => '555']);

        $first  = ClioTrustPaymentSyncService::description('Payment Middleware FLUIDPAY txn abc', $invoice, '77');
        $second = ClioTrustPaymentSyncService::description('Payment Middleware FLUIDPAY txn abc', $invoice, '77');

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Trust Request #1042', $first);
        $this->assertNotSame($first, ClioTrustPaymentSyncService::description('Payment Middleware FLUIDPAY txn abc', $invoice, '78'));
    }

    public function test_webhooks_close_to_expiry_or_without_one_are_renewed(): void
    {
        $renewBefore = CarbonImmutable::parse('2026-11-06 00:00:00');

        $this->assertTrue(RenewClioWebhooksJob::needsRenewal(null, $renewBefore));
        $this->assertTrue(RenewClioWebhooksJob::needsRenewal('2026-11-01 00:00:00', $renewBefore));
        $this->assertFalse(RenewClioWebhooksJob::needsRenewal('2026-11-20 00:00:00', $renewBefore));
    }
}
