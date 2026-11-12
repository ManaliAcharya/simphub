<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Arr;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\EmailConfiguration;

/**
 * Which payment email template an invoice gets, and that template's wording. Law firms
 * (Clio, Lawcus) send different emails per trigger: a Trust Request is a retainer deposit
 * request, a regular invoice uses the billable or flat fee template — by its line items on
 * Lawcus, by its matter's billing method on Clio. Every other PMS — and any invoice whose
 * type is unknown — uses the billable template. The email configuration's own subject/body
 * is the base every template starts from: a law firm's billable hours and flat fee
 * templates override only the fields the firm filled in.
 */
final class PaymentEmailTemplate
{
    public const BILLABLE = 'billable_invoice';

    public const FLAT_FEE = 'flat_fee_invoice';

    public const RETAINER = 'retainer_request';

    /** The law firm templates stored in email_configurations.templates, over the base columns. */
    public const EXTRA_KINDS = [self::BILLABLE, self::FLAT_FEE, self::RETAINER];

    /** Retainer requests never fall back to invoice wording. */
    public const RETAINER_SUBJECT         = 'Retainer Request #{invoice_number} – Deposit Requested';
    public const RETAINER_SUBJECT_UPDATED = 'Updated: Retainer Request #{invoice_number} – Deposit Requested';

    public const RETAINER_REMINDER_SUBJECT = 'Reminder: Retainer Request {invoice_number} is awaiting your deposit';

    private const LAW_PMS = ['clio', 'lawcus'];

    public static function kindFor(Invoice $invoice): string
    {
        if (! self::isLawFirm($invoice)) {
            return self::BILLABLE;
        }

        if (strtoupper((string) $invoice->fund_type) === 'TRUST') {
            return self::RETAINER;
        }

        $flatFeeOnly = strtolower((string) $invoice->pms_source) === 'lawcus'
            ? self::hasOnlyLawcusFlatFees($invoice)
            : null;

        return ($flatFeeOnly ?? self::isFlatFeeMatter($invoice)) ? self::FLAT_FEE : self::BILLABLE;
    }

    /**
     * The template for this invoice: subject, subject_updated, reminder_subject, body_header,
     * body_footer, attach_pdf and kind. A billable hours or flat fee field left blank uses the
     * base email settings; the base reminder subject is the client's own (Reminders
     * settings), passed in.
     *
     * @return array{kind: string, subject: ?string, subject_updated: ?string, reminder_subject: ?string, body_header: ?string, body_footer: ?string, attach_pdf: bool}
     */
    public static function resolve(Invoice $invoice, ?EmailConfiguration $config, ?string $reminderSubject = null): array
    {
        $kind = self::kindFor($invoice);

        $base = [
            'subject'          => $config?->subject_template,
            'subject_updated'  => $config?->subject_template_updated ?: $config?->subject_template,
            'reminder_subject' => $reminderSubject,
            'body_header'      => $config?->body_header,
            'body_footer'      => $config?->body_footer,
            'attach_pdf'       => $config === null || (bool) $config->attach_pdf,
        ];

        // Other PMS have no per-trigger templates.
        if (! self::isLawFirm($invoice)) {
            return ['kind' => $kind] + $base;
        }

        $own = array_filter(
            (array) Arr::get((array) ($config?->templates ?? []), $kind, []),
            fn ($value) => $value !== null && $value !== '',
        );

        // A retainer request reads as a deposit request even before the firm writes its own.
        $fallback = $kind === self::RETAINER
            ? ['subject' => self::RETAINER_SUBJECT, 'subject_updated' => self::RETAINER_SUBJECT_UPDATED,
               'reminder_subject' => self::RETAINER_REMINDER_SUBJECT,
               'body_header' => null, 'body_footer' => null, 'attach_pdf' => false]
            : $base;

        $template = array_merge($fallback, $own);
        $template['attach_pdf'] = (bool) $template['attach_pdf'];

        return ['kind' => $kind] + $template;
    }

    private static function isLawFirm(Invoice $invoice): bool
    {
        return in_array(strtolower((string) $invoice->pms_source), self::LAW_PMS, true);
    }

    /**
     * Whether a Lawcus invoice bills only flat fees. GET /invoices/{id} carries the lines as
     * timeentries, flatfees and expenses; any time entry, any expense or no lines at all means
     * billable. Non-billable lines aren't charged, so they don't count. The matter's
     * billing_type can't decide this — Lawcus reports HOURLY on matters billed by flat fee.
     * Null when the payload has no line items (then the matter's billing type is used).
     */
    private static function hasOnlyLawcusFlatFees(Invoice $invoice): ?bool
    {
        $data = (array) Arr::get((array) ($invoice->raw_payload ?? []), 'invoice.data', []);

        if (! is_array($data['timeentries'] ?? null) && ! is_array($data['flatfees'] ?? null) && ! is_array($data['expenses'] ?? null)) {
            return null;
        }

        $billed = fn (string $key): int => collect((array) ($data[$key] ?? []))
            ->filter(fn ($line) => is_array($line) && ! filter_var($line['is_non_billable'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->count();

        return $billed('flatfees') > 0 && $billed('timeentries') === 0 && $billed('expenses') === 0;
    }

    /**
     * Whether the invoice's matter bills a flat fee. Clio's matter has billing_method
     * ("flat"); Lawcus's invoice carries its matter's billing type, when it sends one.
     */
    private static function isFlatFeeMatter(Invoice $invoice): bool
    {
        $raw = (array) ($invoice->raw_payload ?? []);

        $billingType = Arr::get($raw, 'matter.data.billing_method')
            ?? Arr::get($raw, 'invoice.data.matter.billing_method')
            ?? Arr::get($raw, 'invoice.data.matter.billing_type')
            ?? Arr::get($raw, 'invoice.data.matter_billing_type');

        return is_string($billingType) && str_contains(strtolower($billingType), 'flat');
    }
}
