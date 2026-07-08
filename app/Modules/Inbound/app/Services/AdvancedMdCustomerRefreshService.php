<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\AdvancedMdPractice;

class AdvancedMdCustomerRefreshService
{
    public function __construct(
        private readonly AdvancedMdApiClient $api,
        private readonly AdvancedMdSessionService $session,
    ) {}

    /**
     * Re-fetch patient demographics for an AdvancedMD invoice and patch
     * raw_payload.patient with the latest data (name, email, phone).
     *
     * Returns the updated invoice (unsaved) so the caller decides whether to
     * persist. Returns null when preconditions are not met or the API call fails.
     */
    public function refreshCustomerPayload(Invoice $invoice): ?Invoice
    {
        if ((string) $invoice->pms_source !== 'advancedmd') {
            return null;
        }

        $patientId = trim((string) ($invoice->external_client_id ?? ''));
        if ($patientId === '') {
            return null;
        }

        $practice = AdvancedMdPractice::query()
            ->where('pms_client_id', $invoice->pms_client_id)
            ->where('is_active', true)
            ->first();

        if (! $practice) {
            return null;
        }

        try {
            $practice    = $this->session->ensureValidSession($practice);
            $result      = $this->api->searchPatients($practice, $patientId);
            $list        = $result['patients'] ?? $result;
            $patientData = Arr::first((array) $list);
        } catch (\Throwable) {
            return null;
        }

        if (empty($patientData) || ! is_array($patientData)) {
            return null;
        }

        $rawPayload            = (array) ($invoice->raw_payload ?? []);
        $rawPayload['patient'] = $patientData;
        $invoice->raw_payload  = $rawPayload;

        return $invoice;
    }
}
