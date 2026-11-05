<?php

namespace Modules\Inbound\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Payment\Services\QbFeeOverride;

/**
 * "Ready to Send?" — when a QuickBooks invoice save emails the payment link.
 *
 * - auto: every save sends, as SimpHub always has (resends follow auto_resend_on_change).
 * - trigger: a QB custom field is the key. While it isn't Yes (blank, No or anything else)
 *   the invoice syncs but nothing is sent; once it's Yes, every save sends, so the merchant
 *   never leaves QB.
 * - manual: nothing auto-sends; the merchant clicks Send on the Invoice List tab.
 *
 * The Invoice List Send/Resend button works in every mode.
 */
class QbReadyToSend
{
    public const AUTO = 'auto';
    public const TRIGGER = 'trigger';
    public const MANUAL = 'manual';

    public const MODES = [self::AUTO, self::TRIGGER, self::MANUAL];

    public const DEFAULT_FIELD = 'Ready to Send';

    public static function mode(?Client $client): string
    {
        $mode = (string) ($client?->ready_to_send_mode ?? self::AUTO);

        return in_array($mode, self::MODES, true) ? $mode : self::AUTO;
    }

    /**
     * Trigger-Send's field name: the Per-Invoice Override field when the merchant chose to
     * share it (and the override is on), otherwise its own.
     */
    public static function fieldName(Client $client): string
    {
        $name = self::usesOverrideField($client)
            ? ($client->qb_fee_override_field ?: QbFeeOverride::DEFAULT_FIELD)
            : $client->ready_to_send_field;

        return trim((string) $name) ?: self::DEFAULT_FIELD;
    }

    public static function usesOverrideField(Client $client): bool
    {
        return (bool) $client->ready_to_send_use_override_field && (bool) $client->qb_fee_override_enabled;
    }

    /**
     * Whether Trigger-Send is reading the Per-Invoice Override field — while it is, the
     * override can't be turned off, or invoices would silently stop sending.
     */
    public static function dependsOnOverrideField(?Client $client): bool
    {
        return self::mode($client) === self::TRIGGER && (bool) $client?->ready_to_send_use_override_field;
    }

    /**
     * Shared-field mode: Trigger-Send is on and reads the (enabled) Per-Invoice Override
     * field. Built for QBO Simple Start, which allows only one custom field, so that one
     * field both releases the invoice and picks the fee (and the Multi-MID route).
     */
    public static function sharesOverrideField(?Client $client): bool
    {
        return $client !== null && self::mode($client) === self::TRIGGER && self::usesOverrideField($client);
    }

    /** Values that count as Yes / No, compared trimmed and case-insensitively. */
    public const YES_VALUES = ['yes', 'y'];
    public const NO_VALUES = ['no', 'n'];

    /**
     * Whether the trigger field releases the invoice. Pass an invoice whose raw_payload
     * holds the version just saved in QB.
     *
     * - Own field: only Yes sends. Blank, No and anything else (e.g. a typo) hold, so a
     *   data-entry mistake never emails a customer early.
     * - Shared field: Yes sends with the fee passed and No sends with it absorbed (each
     *   through its Multi-MID route, see QbFeeOverride::passesFee()); blank and anything
     *   else hold.
     */
    public static function triggerOn(Client $client, Invoice $invoice): bool
    {
        $value = self::invoiceFieldValue($invoice, self::fieldName($client));

        if ($value === null) {
            return false;
        }

        $sending = self::sharesOverrideField($client)
            ? [...self::YES_VALUES, ...self::NO_VALUES]
            : self::YES_VALUES;

        return in_array(strtolower($value), $sending, true);
    }

    /**
     * The field's trimmed value on the invoice itself — never the customer, unlike
     * QbFeeOverride::fieldValue(): sending is decided per invoice, so a customer-level
     * field must not send all of that customer's invoices. null when blank or missing.
     */
    private static function invoiceFieldValue(Invoice $invoice, string $fieldName): ?string
    {
        $payload = (array) ($invoice->raw_payload ?? []);

        $candidates = [
            $payload['invoice']['Invoice']['CustomField'] ?? [],   // QB invoice
            $payload['CustomField'] ?? [],                          // manually created test invoices
        ];

        foreach ($candidates as $fields) {
            foreach ((array) $fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $name  = $field['Name'] ?? $field['name'] ?? '';
                $value = $field['StringValue'] ?? $field['string_value'] ?? $field['value'] ?? null;

                if (strcasecmp((string) $name, $fieldName) === 0 && is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }

    /**
     * The initial sync (QuickBooksInvoiceIngestionService::ingest()). Trigger mode only
     * sends here when SimpHub first sees the invoice: on later saves ingest() and
     * ProcessQBInvoiceLinkJob both run, and the job owns the send so the customer
     * doesn't get two emails for one save.
     */
    public static function sendsOnSync(?Client $client, Invoice $invoice, bool $firstSync): bool
    {
        return match (self::mode($client)) {
            self::MANUAL  => false,
            self::TRIGGER => $firstSync && self::triggerOn($client, $invoice),
            default       => true,
        };
    }
}
