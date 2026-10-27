<?php

namespace Modules\Payment\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;

/**
 * QuickBooks Per-Invoice Fee Override: a QB custom field on the invoice (or the
 * customer) decides whether the processing fee is passed to the customer.
 *
 * "Yes" passes the fee and "No" absorbs it. A blank or missing field follows the
 * merchant's configured default (absorb unless they chose "pass"). Any other value,
 * e.g. a typo, absorbs, so a data-entry mistake never charges a customer.
 *
 * Shared by the payment page, checkout and Multi-MID routing so they always agree.
 */
class QbFeeOverride
{
    public const BLANK_ABSORB = 'absorb';
    public const BLANK_PASS = 'pass';

    public const DEFAULT_FIELD = 'Cash Discount';

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
     * The field's trimmed value, checking the invoice first, then the customer, then the
     * top level (manually created test invoices). Blank values are skipped, so a blank
     * invoice field falls through to a customer-level value. null when none is set.
     */
    public static function fieldValue(Invoice $invoice, string $fieldName): ?string
    {
        $payload = (array) ($invoice->raw_payload ?? []);

        $candidates = [
            $payload['invoice']['Invoice']['CustomField'] ?? [],   // transaction-level field
            $payload['customer']['Customer']['CustomField'] ?? [],  // customer-level field
            $payload['CustomField'] ?? [],                          // manually created test invoices
        ];

        foreach ($candidates as $fields) {
            if (! is_array($fields) || empty($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                $name  = $field['Name'] ?? $field['name'] ?? '';
                $value = $field['StringValue'] ?? $field['string_value'] ?? $field['value'] ?? null;

                if (strcasecmp((string) $name, $fieldName) === 0
                    && $value !== null
                    && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }
}
