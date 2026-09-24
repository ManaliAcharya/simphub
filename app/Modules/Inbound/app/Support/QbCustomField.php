<?php

namespace Modules\Inbound\Support;

class QbCustomField
{
    /**
     * Find a named custom field's value in a QuickBooks invoice raw_payload — checks
     * invoice-level, then customer-level, then top-level (manually created test invoices).
     * Field-name matching is case-insensitive; an all-whitespace value is treated as absent.
     */
    public static function extract(array $rawPayload, string $fieldName): ?string
    {
        $candidates = [
            $rawPayload['invoice']['Invoice']['CustomField'] ?? [],
            $rawPayload['customer']['Customer']['CustomField'] ?? [],
            $rawPayload['CustomField'] ?? [],
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

    /**
     * Loosely matches "yes"/"Yes"/"YES"/"Y"/"y" (trimmed) — wider than the exact-"yes"
     * match used elsewhere (e.g. the Cash Discount fee-override field).
     */
    public static function isYes(?string $value): bool
    {
        return $value !== null && in_array(strtolower(trim($value)), ['yes', 'y'], true);
    }
}
