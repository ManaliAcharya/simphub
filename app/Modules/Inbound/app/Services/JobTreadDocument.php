<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;

/**
 * Rules for reading a JobTread document (as returned by JobTreadApiClient::fetchDocument).
 * Pure functions, so the billing rules can be tested without the API.
 */
final class JobTreadDocument
{
    public static function type(array $document): string
    {
        return (string) ($document['type'] ?? '');
    }

    public static function status(array $document): string
    {
        return strtolower((string) ($document['status'] ?? ''));
    }

    public static function isCustomerInvoice(array $document): bool
    {
        return self::type($document) === JobTreadPave::TYPE_CUSTOMER_INVOICE;
    }

    public static function isPending(array $document): bool
    {
        return self::status($document) === JobTreadPave::STATUS_PENDING;
    }

    /** Taken back to Draft with Make Changes: not payable until it's pushed to pending again. */
    public static function isDraft(array $document): bool
    {
        return self::status($document) === JobTreadPave::STATUS_DRAFT;
    }

    /** Denied: the invoice is dead, so its link closes. */
    public static function isDenied(array $document): bool
    {
        return self::status($document) === JobTreadPave::STATUS_DENIED;
    }

    /** Invoice total including tax, in cents, or null when JobTread didn't give it. */
    public static function totalCents(array $document): ?int
    {
        $price = $document['price'] ?? null;

        if (! is_numeric($price)) {
            return null;
        }

        $tax = is_numeric($document['tax'] ?? null) ? (float) $document['tax'] : 0.0;

        return self::cents((float) $price + $tax);
    }

    /**
     * What's still owed, in cents: JobTread's balance when it gives one, otherwise the total
     * minus the payments applied to it. Null when neither can be worked out.
     */
    public static function balanceCents(array $document): ?int
    {
        if (is_numeric($document['balance'] ?? null)) {
            return max(0, self::cents((float) $document['balance']));
        }

        $total = self::totalCents($document);

        if ($total === null) {
            return null;
        }

        $paid = collect((array) Arr::get($document, 'documentPayments.nodes', []))
            ->sum(fn ($node) => is_array($node) && is_numeric($node['amount'] ?? null) ? self::cents((float) $node['amount']) : 0);

        return max(0, $total - (int) $paid);
    }

    /** Pending with something left to pay: the only state a customer may pay in. */
    public static function isPayable(array $document): bool
    {
        $balance = self::balanceCents($document);

        return self::isCustomerInvoice($document)
            && self::isPending($document)
            && $balance !== null
            && $balance > 0;
    }

    /**
     * Who the invoice goes to: the document's own "to" address, otherwise its recipients
     * (their address, or their user's), skipping users JobTread has suppressed (bounced or
     * unsubscribed).
     *
     * @return list<string>
     */
    public static function recipientEmails(array $document): array
    {
        $emails = [];

        if (is_string($document['toEmailAddress'] ?? null)) {
            $emails[] = $document['toEmailAddress'];
        }

        if ($emails === [] || ! self::validEmails($emails)) {
            foreach ((array) Arr::get($document, 'documentRecipients.nodes', []) as $recipient) {
                if (! is_array($recipient) || Arr::get($recipient, 'user.hasSuppressedEmailAddress') === true) {
                    continue;
                }

                $emails[] = Arr::get($recipient, 'user.emailAddress');
            }
        }

        return self::validEmails($emails);
    }

    public static function customerName(array $document): ?string
    {
        foreach ([
            $document['toName'] ?? null,
            Arr::get($document, 'job.location.account.name'),
            Arr::get($document, 'documentRecipients.nodes.0.user.name'),
        ] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    /**
     * Testing on a live account: when JOBTREAD_ONLY_RECIPIENTS or JOBTREAD_ONLY_ACCOUNT_IDS is
     * set, only invoices for those recipients or customer accounts are processed. With neither
     * set, every invoice passes.
     */
    public static function passesTestFilter(array $document): bool
    {
        $recipients = (array) config('services.jobtread.only_recipients', []);
        $accounts   = (array) config('services.jobtread.only_account_ids', []);

        if ($recipients === [] && $accounts === []) {
            return true;
        }

        if ($accounts !== [] && in_array((string) Arr::get($document, 'job.location.account.id'), $accounts, true)) {
            return true;
        }

        return $recipients !== [] && array_intersect(self::recipientEmails($document), $recipients) !== [];
    }

    /** The invoice number staff and the customer see. */
    public static function number(array $document): string
    {
        $number = trim((string) ($document['number'] ?? ''));

        return $number !== '' ? $number : trim((string) ($document['name'] ?? ''));
    }

    /**
     * Whether JobTread itself emailed the invoice (someone clicked Send instead of Push to
     * Pending): a recipient has a delivery status. VERIFY the status values (first-day test #2).
     */
    public static function emailedByJobTread(array $document): bool
    {
        foreach ((array) Arr::get($document, 'documentRecipients.nodes', []) as $recipient) {
            $status = strtolower(trim((string) (is_array($recipient) ? ($recipient['emailDeliveryStatus'] ?? '') : '')));

            if ($status !== '' && ! in_array($status, ['none', 'notsent', 'not_sent', 'null'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fingerprint of what the customer sees on the invoice, not of its balance (payments
     * don't count as an edit). Comparing it detects Make Changes + Push to Pending even when
     * both happened between two polls, so the status alone still reads "pending".
     */
    public static function fingerprint(array $document): string
    {
        return sha1(json_encode([
            self::number($document),
            $document['name'] ?? null,
            self::totalCents($document),
            $document['issueDate'] ?? null,
            $document['dueDate'] ?? null,
            $document['toEmailAddress'] ?? null,
        ]));
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** @return list<string> */
    private static function validEmails(array $emails): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($email) => is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? strtolower(trim($email)) : null,
            $emails,
        ))));
    }
}
