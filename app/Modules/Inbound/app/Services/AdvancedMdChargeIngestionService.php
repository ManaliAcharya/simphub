<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\AdvancedMdPractice;
use Modules\Payment\Services\PaymentLinkService;

class AdvancedMdChargeIngestionService
{
    public function __construct(
        private readonly AdvancedMdApiClient $api,
        private readonly AdvancedMdSessionService $session,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    /**
     * Ingest a charge: resolve patient demographics, create Invoice +
     * PaymentSession, and send a payment link to the patient.
     *
     * $charge is one entry from AdvancedMdApiClient::listChargesSince()'s
     * "charges" list — it already carries amount/patient data from the
     * getupdatedvisits field-selectors, so no getChargeDetail call is needed.
     *
     * Returns ['skipped' => true] when patient balance is zero.
     */
    public function ingest(AdvancedMdPractice $practice, array $charge): array
    {
        $practice = $this->session->ensureValidSession($practice);

        if ($charge['amount_cents'] <= 0) {
            Log::info('AdvancedMD charge skipped: zero patient balance', [
                'practice_id' => $practice->id,
                'charge_id'   => $charge['charge_id'],
            ]);

            return ['skipped' => true, 'reason' => 'zero_balance'];
        }

        $patientData     = $this->fetchPatient($practice, $charge['external_client_id'], $charge['patient_name']);
        $recipientEmails = $this->extractEmails($patientData);

        $result = DB::transaction(function () use ($charge, $patientData, $practice, $recipientEmails) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source'          => 'advancedmd',
                    'pms_client_id'       => $practice->pms_client_id,
                    'external_invoice_id' => $charge['charge_id'],
                ],
                [
                    'pms_client_id'      => $practice->pms_client_id,
                    'invoice_number'     => $charge['charge_id'],
                    'external_client_id' => $charge['external_client_id'],
                    'status'             => 'PENDING',
                    'fund_type'          => 'OPERATING',
                    'amount_cents'       => $charge['amount_cents'],
                    'currency'           => 'USD',
                    'pms_sync_status'    => 'SYNCED',
                    'raw_payload'        => [
                        'charge'  => $charge,
                        'patient' => $patientData,
                    ],
                    'recipient_emails'   => $recipientEmails,
                    'synced_at'          => now(),
                ]
            );

            Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            $createdSession = false;

            if (! $session) {
                $session        = $this->paymentSessions->create($invoice);
                $createdSession = true;
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source'          => 'advancedmd',
                'pms_client_id'       => $practice->pms_client_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id'  => $session->id,
            ]);

            return [
                'invoice'         => $invoice->fresh(),
                'payment_session' => $session->fresh(),
                'created_session' => $createdSession,
            ];
        });

        $emailsSent = 0;

        if (! empty($recipientEmails)) {
            $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce(
                $result['invoice'],
                $result['payment_session'],
                $recipientEmails
            );
        }

        if ($emailsSent > 0) {
            AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $result['payment_session']->id, [
                'invoice_id'    => $result['invoice']->id,
                'pms_client_id' => $practice->pms_client_id,
                'emails_sent'   => $emailsSent,
            ]);
        }

        $result['emails_sent'] = $emailsSent;

        return $result;
    }

    /**
     * The REST PM lookup/patients endpoint searches by name — passing the raw
     * AMD patient ID returns no results. Search by name, then filter down to
     * the exact patient ID since a name/chart-number query can match others
     * (e.g. "31" also substring-matches chart number "315").
     */
    private function fetchPatient(AdvancedMdPractice $practice, string $patientId, string $patientName): array
    {
        if ($patientId === '' || $patientName === '') {
            return [];
        }

        try {
            $result = $this->api->searchPatients($practice, $patientName);
            $list   = (array) ($result['patients'] ?? $result);

            $patient = Arr::first(
                $list,
                fn ($candidate) => is_array($candidate) && (string) ($candidate['id'] ?? '') === $patientId
            );

            return is_array($patient) ? $patient : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function extractEmails(array $patientData): array
    {
        $candidates = [
            Arr::get($patientData, 'email'),
            Arr::get($patientData, 'emailaddress'),
            Arr::get($patientData, 'emailAddress'),
            Arr::get($patientData, 'Email'),
        ];

        $emails = array_filter(
            array_map(
                static fn ($v) => is_string($v) && str_contains($v, '@') ? trim($v) : null,
                $candidates
            )
        );

        return array_values(array_unique($emails));
    }
}
