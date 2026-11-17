<?php

namespace Modules\Inbound\Services;

use Modules\Billing\Models\Invoice;

/**
 * Deep links into the Lawcus web app for firm-facing emails ("Open matter in Lawcus").
 */
class LawcusLinks
{
    /**
     * Lawcus invoices are always created from a matter, so the firm can jump straight
     * to it. Matter pages are addressed by the matter's UUID, and app.lawcus.com opens
     * them for any firm (it redirects to the firm's own subdomain). Null for every
     * other PMS, or when the invoice carries no matter UUID.
     */
    public static function matterUrl(Invoice $invoice): ?string
    {
        if (strtolower((string) $invoice->pms_source) !== 'lawcus') {
            return null;
        }

        $data       = (array) data_get($invoice->raw_payload, 'invoice.data', []);
        $matterUuid = trim((string) (
            $data['matter_uuid'] ?? $data['matteruuid'] ?? data_get($data, 'matter.uuid') ?? ''
        ));

        if ($matterUuid === '') {
            return null;
        }

        return config('services.lawcus.base_url').'/matter/'.rawurlencode($matterUuid);
    }

    /**
     * The invoice's own page, addressed by its UUID. Like matters, app.lawcus.com opens it
     * for any firm by redirecting to the firm's subdomain (checked 2026-11-17). Null for
     * every other PMS, or when the invoice has no Lawcus id.
     */
    public static function invoiceUrl(Invoice $invoice): ?string
    {
        $invoiceUuid = trim((string) $invoice->external_invoice_id);

        if (strtolower((string) $invoice->pms_source) !== 'lawcus' || $invoiceUuid === '') {
            return null;
        }

        return config('services.lawcus.base_url').'/invoices/'.rawurlencode($invoiceUuid);
    }

    /**
     * For "action needed" emails: the invoice page, else the matter.
     *
     * @return array{url: string, label: string}|null
     */
    public static function actionLink(Invoice $invoice): ?array
    {
        if ($url = self::invoiceUrl($invoice)) {
            return ['url' => $url, 'label' => 'Open invoice in Lawcus'];
        }

        if ($url = self::matterUrl($invoice)) {
            return ['url' => $url, 'label' => 'Open matter in Lawcus'];
        }

        return null;
    }

    /** The matter's name as Lawcus sent it with the invoice, or null when it didn't. */
    public static function matterName(Invoice $invoice): ?string
    {
        $data = (array) data_get($invoice->raw_payload, 'invoice.data', []);
        $name = trim((string) (
            $data['matter_name'] ?? data_get($data, 'matter.name') ?? data_get($data, 'matter.display_number') ?? ''
        ));

        return $name !== '' ? $name : null;
    }
}
