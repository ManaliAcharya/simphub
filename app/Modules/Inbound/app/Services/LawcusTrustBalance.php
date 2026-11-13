<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\LawcusConnection;
use Throwable;

/**
 * "Available trust balance" on a Lawcus invoice's email and PDF (informational only).
 *
 * Matches what Lawcus prints with the invoice's "Show Trust Balance" toggle on: the trust
 * balance of the invoice's matter, as firms apply trust per matter. Trust held for the
 * client but not on a matter isn't shown — Lawcus' own invoice PDF leaves it out too.
 * Nothing is shown for a zero balance, without the toggle, or when Lawcus can't be reached.
 */
class LawcusTrustBalance
{
    public function __construct(
        private readonly LawcusApiClient $api,
        private readonly LawcusOAuthService $oauth,
    ) {}

    /** The matter's trust balance in cents, or null when nothing should be shown. */
    public function forInvoice(Invoice $invoice): ?int
    {
        $data = (array) Arr::get((array) ($invoice->raw_payload ?? []), 'invoice.data', []);

        if ($invoice->pms_source !== 'lawcus'
            || ! filter_var($data['show_trust_balance'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ! is_numeric($data['client_id'] ?? null)) {
            return null;
        }

        $matterNumber = trim((string) ($data['matter_display_number'] ?? data_get($data, 'matter.display_number') ?? ''));
        $matterName   = trim((string) ($data['matter_name'] ?? $data['mattername'] ?? data_get($data, 'matter.name') ?? ''));

        if ($matterNumber === '' && $matterName === '') {
            return null;
        }

        $connection = LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->latest('created_at')
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            $report = $this->api->fetchClientTrust($this->oauth->ensureValidAccessToken($connection), (int) $data['client_id']);
        } catch (Throwable $e) {
            Log::warning('Lawcus trust balance could not be loaded; leaving it off the invoice', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        $cents = collect($report)
            ->filter(fn ($group) => is_array($group) && (string) ($group['key'] ?? '') === (string) $data['client_id'])
            ->flatMap(fn (array $group) => (array) ($group['accounts'] ?? []))
            ->filter(fn ($account) => is_array($account) && self::isMatter($account, $matterNumber, $matterName))
            ->sum(fn (array $account) => (int) round((float) ($account['total'] ?? 0) * 100));

        return $cents > 0 ? (int) $cents : null;
    }

    /** "1,555.00"-style amount for the email and PDF, or null. */
    public function formatted(Invoice $invoice): ?string
    {
        $cents = $this->forInvoice($invoice);

        return $cents === null ? null : number_format($cents / 100, 2);
    }

    /** A trust account row held on the invoice's matter (client-level rows have no matter). */
    private static function isMatter(array $account, string $matterNumber, string $matterName): bool
    {
        $number = trim((string) ($account['matter_display_number'] ?? ''));
        $name   = trim((string) ($account['matter_name'] ?? ''));

        if ($number !== '' && $matterNumber !== '') {
            return $number === $matterNumber;
        }

        return $name !== '' && $matterName !== '' && $name === $matterName;
    }
}
