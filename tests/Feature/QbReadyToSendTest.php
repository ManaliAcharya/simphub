<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\QbReadyToSend;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QbReadyToSendTest extends TestCase
{
    public static function syncDecisions(): array
    {
        // [mode, invoice field value, first sync?, sends?]
        return [
            'auto, first sync' => ['auto', null, true, true],
            'auto, later save' => ['auto', null, false, true],
            'manual, first sync' => ['manual', 'Yes', true, false],
            'manual, later save' => ['manual', 'Yes', false, false],
            'trigger, first sync, Yes' => ['trigger', 'Yes', true, true],
            'trigger, first sync, yes (any case, padded)' => ['trigger', ' YES ', true, true],
            'trigger, first sync, Y' => ['trigger', 'y', true, true],
            'trigger, first sync, No' => ['trigger', 'No', true, false],
            'trigger, first sync, other value' => ['trigger', 'go', true, false],
            'trigger, first sync, blank' => ['trigger', null, true, false],
            'trigger, first sync, whitespace only' => ['trigger', '   ', true, false],
            // Later saves are sent by ProcessQBInvoiceLinkJob, not by ingestion.
            'trigger, later save, filled' => ['trigger', 'Yes', false, false],
        ];
    }

    #[DataProvider('syncDecisions')]
    public function test_decides_whether_the_initial_sync_sends(string $mode, ?string $fieldValue, bool $firstSync, bool $sends): void
    {
        $client = (new Client)->forceFill([
            'ready_to_send_mode' => $mode,
            'ready_to_send_field' => 'Ready to Send',
        ]);

        $this->assertSame($sends, QbReadyToSend::sendsOnSync($client, $this->invoiceWith($fieldValue), $firstSync));
    }

    public function test_trigger_field_name_is_configurable_and_case_insensitive(): void
    {
        $client = (new Client)->forceFill([
            'ready_to_send_mode' => 'trigger',
            'ready_to_send_field' => 'Send Now',
        ]);

        $this->assertTrue(QbReadyToSend::triggerOn($client, $this->invoiceWith('Yes', 'send now')));
        $this->assertFalse(QbReadyToSend::triggerOn($client, $this->invoiceWith('Yes', 'Ready to Send')));
    }

    public function test_only_the_invoice_field_counts_never_the_customer(): void
    {
        $client = (new Client)->forceFill(['ready_to_send_mode' => 'trigger', 'ready_to_send_field' => 'Ready to Send']);

        $customerYes = ['Customer' => ['CustomField' => [['Name' => 'Ready to Send', 'StringValue' => 'Yes']]]];

        $blankInvoice = (new Invoice)->forceFill(['raw_payload' => [
            'invoice' => ['Invoice' => ['CustomField' => []]],
            'customer' => $customerYes,
        ]]);
        $this->assertFalse(QbReadyToSend::triggerOn($client, $blankInvoice));

        $yesInvoice = $this->invoiceWith('Yes');
        $yesInvoice->raw_payload = [...$yesInvoice->raw_payload, 'customer' => $customerYes];
        $this->assertTrue(QbReadyToSend::triggerOn($client, $yesInvoice));
    }

    public function test_blank_field_name_falls_back_to_the_default(): void
    {
        $client = (new Client)->forceFill(['ready_to_send_mode' => 'trigger', 'ready_to_send_field' => '']);

        $this->assertTrue(QbReadyToSend::triggerOn($client, $this->invoiceWith('Yes')));
    }

    public function test_missing_or_unknown_mode_behaves_as_auto(): void
    {
        $this->assertSame('auto', QbReadyToSend::mode(null));
        $this->assertSame('auto', QbReadyToSend::mode(new Client));
        $this->assertSame('auto', QbReadyToSend::mode((new Client)->forceFill(['ready_to_send_mode' => 'bogus'])));
    }

    public function test_shared_field_follows_the_per_invoice_override_name(): void
    {
        $client = (new Client)->forceFill([
            'ready_to_send_mode' => 'trigger',
            'ready_to_send_field' => 'Ready to Send',
            'ready_to_send_use_override_field' => true,
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Fee Override',
        ]);

        $this->assertSame('Fee Override', QbReadyToSend::fieldName($client));
        $this->assertTrue(QbReadyToSend::triggerOn($client, $this->invoiceWith('Yes', 'Fee Override')));
        $this->assertFalse(QbReadyToSend::triggerOn($client, $this->invoiceWith('Yes', 'Ready to Send')));

        // Renaming the override field carries Trigger-Send with it.
        $client->qb_fee_override_field = 'Pass Fee';
        $this->assertSame('Pass Fee', QbReadyToSend::fieldName($client));
    }

    public function test_own_field_is_used_when_not_sharing_or_override_is_off(): void
    {
        $client = (new Client)->forceFill([
            'ready_to_send_mode' => 'trigger',
            'ready_to_send_field' => 'Send Now',
            'ready_to_send_use_override_field' => false,
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Fee Override',
        ]);

        $this->assertSame('Send Now', QbReadyToSend::fieldName($client));

        $client->forceFill(['ready_to_send_use_override_field' => true, 'qb_fee_override_enabled' => false]);
        $this->assertSame('Send Now', QbReadyToSend::fieldName($client));
    }

    public function test_override_is_locked_only_while_trigger_send_shares_its_field(): void
    {
        $client = (new Client)->forceFill(['ready_to_send_mode' => 'trigger', 'ready_to_send_use_override_field' => true]);
        $this->assertTrue(QbReadyToSend::dependsOnOverrideField($client));

        $client->ready_to_send_use_override_field = false;
        $this->assertFalse(QbReadyToSend::dependsOnOverrideField($client));

        $client->forceFill(['ready_to_send_mode' => 'manual', 'ready_to_send_use_override_field' => true]);
        $this->assertFalse(QbReadyToSend::dependsOnOverrideField($client));
    }

    private function invoiceWith(?string $value, string $fieldName = 'Ready to Send'): Invoice
    {
        return (new Invoice)->forceFill(['raw_payload' => [
            'invoice' => ['Invoice' => ['CustomField' => $value === null ? [] : [
                ['Name' => $fieldName, 'StringValue' => $value],
            ]]],
        ]]);
    }
}
