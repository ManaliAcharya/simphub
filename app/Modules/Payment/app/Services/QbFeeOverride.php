<?php

namespace Modules\Payment\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;

/**
 * Per-Invoice Fee Override: a PMS custom field decides whether the processing fee is
 * passed to the customer. QuickBooks reads it from the invoice (or the customer); Clio
 * reads it from the bill's matter (or the client contact), since Clio bills have no
 * custom fields of their own.
 *
 * "Yes" passes the fee and "No" absorbs it. A blank or missing field follows the
 * merchant's configured default (absorb unless they chose "pass"). Any other value,
 * e.g. a typo, absorbs, so a data-entry mistake never charges a customer.
 *
 * Shared by the payment page, checkout and Multi-MID routing so they always agree.
 * The settings live in the qb_* client columns for every supported PMS.
 */
class QbFeeOverride
{
    public const BLANK_ABSORB = 'absorb';
    public const BLANK_PASS = 'pass';

    public const DEFAULT_FIELD = 'Cash Discount';

    /** PMS sources whose invoices carry the override field. */
    public const PMS_SOURCES = ['quickbooks', 'clio'];

    public static function supports(Invoice $invoice): bool
    {
        return in_array((string) $invoice->pms_source, self::PMS_SOURCES, true);
    }

    /**
     * The Multi-MID fund type for this invoice. Clio Trust Requests route to their own
     * (IOLTA) MIDs; everything else, including every QuickBooks invoice, is OPERATING.
     */
    public static function midRouteFundType(Invoice $invoice): string
    {
        return (string) $invoice->pms_source === 'clio' && $invoice->fund_type === 'TRUST'
            ? 'TRUST'
            : 'OPERATING';
    }

    /** Only meaningful when the client has the override enabled. */
    public static function passesFee(Client $client, Invoice $invoice): bool
    {
        $value = self::fieldValue($invoice, (string) ($client->qb_fee_override_field ?? self::DEFAULT_FIELD));

        if ($value === null) {
            return $client->qb_fee_override_blank_default === self::BLANK_PASS;
        }

        return strtolower($value) === 'yes';
    }

    /**
     * The field's trimmed value, checking the QuickBooks invoice first, then the customer,
     * then the Clio matter, then the Clio contact, then the top level (manually created
     * test invoices). Blank values are skipped, so a blank invoice field falls through to
     * a customer-level value. null when none is set.
     */
    public static function fieldValue(Invoice $invoice, string $fieldName): ?string
    {
        $payload = (array) ($invoice->raw_payload ?? []);

        $candidates = [
            $payload['invoice']['Invoice']['CustomField'] ?? [],         // transaction-level field
            $payload['customer']['Customer']['CustomField'] ?? [],        // customer-level field
            $payload['matter']['data']['custom_field_values'] ?? [],      // Clio matter field
            $payload['customer']['data']['custom_field_values'] ?? [],    // Clio contact field
            $payload['CustomField'] ?? [],                                // manually created test invoices
        ];

        foreach ($candidates as $fields) {
            if (! is_array($fields) || empty($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $name  = $field['Name'] ?? $field['name'] ?? $field['field_name'] ?? '';
                $value = self::stringValue($field);

                if (strcasecmp((string) $name, $fieldName) === 0
                    && $value !== null
                    && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * The field's value as the same Yes/No text a QuickBooks field holds. A Clio checkbox
     * holds a boolean, and a Clio picklist holds the chosen option's id, with its text in
     * picklist_option.option.
     */
    private static function stringValue(array $field): ?string
    {
        $option = $field['picklist_option']['option'] ?? null;
        if (is_scalar($option) && trim((string) $option) !== '') {
            return (string) $option;
        }

        $value = $field['StringValue'] ?? $field['string_value'] ?? $field['value'] ?? null;

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (strtolower((string) ($field['field_type'] ?? '')) === 'checkbox' && is_scalar($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
