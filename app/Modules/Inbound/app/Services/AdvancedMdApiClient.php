<?php

namespace Modules\Inbound\Services;

use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\AdvancedMdPractice;
use RuntimeException;
use SimpleXMLElement;

use Illuminate\Support\Facades\Log;
class AdvancedMdApiClient
{
    public function __construct(
        private readonly AdvancedMdRateLimiter $rateLimiter,
    ) {}

    // ── REST PM endpoints (Bearer token, JSON response) ────────────────────────

    /**
     * Search patients via the REST PM endpoint.
     * Pass "!IDENTIFIER" to return all patients, or a specific patient ID/name to filter.
     */
    public function searchPatients(AdvancedMdPractice $practice, string $query = '!IDENTIFIER'): array
    {
        $this->rateLimiter->throttle($practice->office_key, 'lookup_patients');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($practice->session_token)
                ->post($practice->rest_pm_url . '/lookup/patients', ['query' => $query])
                ->throw();
        } catch (RequestException $e) {
            throw $this->wrapIfRateLimited($e, $practice->office_key, 'lookup_patients');
        }

        return $response->json() ?? [];
    }

    // ── XML-RPC endpoints (Cookie token, XML response) ─────────────────────────

    /**
     * Fetch charge detail (ICD-10 version) for a given charge ID.
     * Returns a parsed array; attribute keys are prefixed with "@".
     */
    public function getChargeDetail(AdvancedMdPractice $practice, string $chargeId): array
    {
        $xml = $this->xmlRpc($practice, [
            '@action'   => 'getchargedetaildataicd10',
            '@class'    => 'demographics',
            '@msgtime'  => now()->format('m/d/Y g:i:s A'),
            '@chargeid' => $chargeId,
            '@nocookie' => '0',
        ]);

        return $this->xmlToArray($xml);
    }

    /**
     * Resolve the profile / responsible-party / visit IDs needed to post a payment
     * against a specific charge, plus that charge's current balance snapshot
     * (allowed/insurance/patient portions and balances). These are per-patient/
     * per-visit AMD values (not fixed constants) — required before recordPayment()
     * can post a valid payload. AMD's own documented "addpayments" payment-record
     * shape echoes the charge's current balance fields back as part of the request
     * (not just chargeId + amount) — omitting them appears to make AMD validate the
     * payment against an empty/zero balance context regardless of the charge's real
     * state, which is the likely cause of "exceeds the payee's balance" rejections
     * even against charges confirmed to have a real, unpaid balance.
     *
     * @return array{patient_id: string, visit_id: string, profile_id: string, resp_party_id: string, charge_id: string, allowed: float, insurance_portion: float, patient_portion: float, insurance_balance: float, patient_balance: float}
     */
    public function getChargeBillingContext(AdvancedMdPractice $practice, string $chargeId): array
    {
        $data = $this->getChargeDetail($practice, $chargeId);

        $patient = $this->asNodeList($data['Results']['patientlist']['patient'] ?? [])[0] ?? [];
        $visit   = $this->asNodeList($patient['visitlist']['visit'] ?? [])[0] ?? [];
        $charges = $this->asNodeList($visit['chargelist']['charge'] ?? []);

        $charge = collect($charges)->first(
            fn ($c) => (string) ($c['@id'] ?? '') === $chargeId
        ) ?? ($charges[0] ?? []);

        // AMD's response IDs carry a type-prefix (charge5502389, vst9756656, prof4, resp6984505),
        // but per AMD's API docs, inbound financial requests expect the bare numeric ID —
        // prefixes are only for identifying object type in outbound/response payloads.
        $context = [
            'patient_id'    => $this->stripAmdIdPrefix((string) ($patient['@id'] ?? '')),
            'visit_id'      => $this->stripAmdIdPrefix((string) ($visit['@id'] ?? '')),
            'profile_id'    => $this->stripAmdIdPrefix((string) ($visit['@profile'] ?? '')),
            'resp_party_id' => $this->stripAmdIdPrefix((string) ($charge['@respparty'] ?? '')),
            'charge_id'     => $this->stripAmdIdPrefix((string) ($charge['@id'] ?? $chargeId)),

            'allowed'           => (float) ($charge['@allowed'] ?? 0),
            'insurance_portion' => (float) ($charge['@insportion'] ?? 0),
            'patient_portion'   => (float) ($charge['@patportion'] ?? 0),
            'insurance_balance' => (float) ($charge['@insbalance'] ?? 0),
            'patient_balance'   => (float) ($charge['@patbalance'] ?? 0),
        ];

        Log::info('AdvancedMD getChargeBillingContext resolved', [
            'requested_charge_id' => $chargeId,
            'context'             => $context,
            'patient_found'       => $patient !== [],
            'visit_found'         => $visit !== [],
            'charges_found'       => count($charges),
        ]);

        return $context;
    }

    /**
     * Return new charges (updatestatus="N") created since $datechanged, along
     * with the charge details needed to build an Invoice — the field-selector
     * blocks below request everything AdvancedMdChargeIngestionService needs,
     * so no separate getChargeDetail call is required per charge.
     *
     * Uses getupdatedvisits with the datechanged filter on the root ppmdmsg
     * attribute (not nested under <charge> — AMD ignores it there). The
     * visit/patient/insurance/charge blocks are field-selector templates:
     * each value just names the attribute AMD should include in the response.
     * $datechanged must be the raw AMD servertime string from the previous
     * response — never a reformatted timestamp — to avoid missing overlapping charges.
     *
     * @return array{charges: array<array{charge_id: string, external_client_id: string, patient_name: string, amount_cents: int}>, servertime: string}
     */
    public function listChargesSince(AdvancedMdPractice $practice, string $datechanged): array
    {
        $xml = $this->xmlRpc($practice, [
            '@action'      => 'getupdatedvisits',
            '@class'       => 'api',
            '@msgtime'     => now()->format('m/d/Y g:i:s A'),
            '@datechanged' => $datechanged,
            '@nocookie'    => '0',
            'visit' => [
                '@duration' => 'Duration',
                '@color'    => 'Color',
            ],
            'patient' => [
                '@name'      => 'Name',
                '@ssn'       => 'SSN',
                '@changedat' => 'ChangedAt',
                '@createdat' => 'CreatedAt',
            ],
            'insurance' => [
                '@carname'   => 'CarName',
                '@carcode'   => 'CarCode',
                '@carcity'   => 'CarCity',
                '@changedat' => 'ChangedAt',
                '@createdat' => 'CreatedAt',
            ],
            'charge' => [
                '@Insbalance'          => 'Insbalance',
                '@patbalance'          => 'patbalance',
                '@chargecode'          => 'chargecode',
                '@units'               => 'units',
                '@fee'                 => 'fee',
                '@patientportion'      => 'patientportion',
                '@CreatedAt'           => 'CreatedAt',
                '@ChangedAt'           => 'ChangedAt',
                '@PostingDate'         => 'PostingDate',
                '@BeginDateOfService'  => 'BeginDateOfService',
                '@EndDateOfService'    => 'EndDateOfService',
                '@DiagnosisCodesICD10' => 'DiagnosisCodesICD10',
                '@Modifiers'           => 'Modifiers',
                '@posvalue'            => 'posvalue',
                '@tosvalue'            => 'tosvalue',
                '@financialclasscode'  => 'financialclasscode',
            ],
        ]);

        $data = $this->xmlToArray($xml);

        // AMD returns servertime on the root element or Results — store it verbatim.
        $servertime = (string) ($data['@servertime']
            ?? $data['Results']['@servertime']
            ?? $datechanged);

        // Real response shape: Results.visitlist.visit(.patientlist.patient.chargelist.charge)+
        // A single node at any level comes back as an assoc array; normalise each level to a list.
        $visits = $data['Results']['visitlist']['visit'] ?? [];
        $visits = $this->asNodeList($visits);

        $charges  = [];
        $seenIds  = [];

        // Diagnostics only — lets logs distinguish "AMD returned nothing for this
        // window" from "AMD returned charges but none had updatestatus=N".
        $chargeNodesSeen = 0;
        $skippedStatuses = [];

        foreach ($visits as $visit) {
            $patients = $this->asNodeList($visit['patientlist']['patient'] ?? []);

            foreach ($patients as $patient) {
                $patientId   = (string) ($patient['@id'] ?? '');
                $patientName = (string) ($patient['@name'] ?? '');

                foreach ($this->asNodeList($patient['chargelist']['charge'] ?? []) as $charge) {
                    $chargeNodesSeen++;

                    $status   = strtoupper((string) ($charge['@updatestatus'] ?? ''));
                    $chargeId = (string) ($charge['@chargeid'] ?? $charge['@id'] ?? '');

                    if ($status !== 'N' || $chargeId === '' || isset($seenIds[$chargeId])) {
                        if ($status !== 'N') {
                            $skippedStatuses[$status] = ($skippedStatuses[$status] ?? 0) + 1;
                        }
                        continue;
                    }

                    $seenIds[$chargeId] = true;

                    $patBalance = (float) ($charge['@patbalance'] ?? $charge['@patientportion'] ?? 0);

                    $charges[] = [
                        'charge_id'          => $chargeId,
                        'external_client_id' => $patientId,
                        'patient_name'       => $patientName,
                        'amount_cents'       => (int) round($patBalance * 100),
                    ];
                }
            }
        }

        Log::info('AdvancedMD listChargesSince result', [
            'practice_id'       => $practice->id,
            'datechanged'       => $datechanged,
            'servertime'        => $servertime,
            'visits_found'      => count($visits),
            'charge_nodes_seen' => $chargeNodesSeen,
            'charges_matched'   => count($charges),
            'skipped_statuses'  => $skippedStatuses,
        ]);

        return [
            'charges'    => $charges,
            'servertime' => $servertime,
        ];
    }

    /**
     * xmlToArray() collapses a single child element to an assoc array instead of
     * a one-item list. Normalise any node (single assoc array or list) to a list.
     */
    private function asNodeList(array $node): array
    {
        if (empty($node)) {
            return [];
        }

        // A list of nodes already has integer keys; a single node has string "@..." keys.
        return array_is_list($node) ? $node : [$node];
    }

    /**
     * Strip AMD's object-type prefix (charge5502389 -> 5502389, vst9756656 -> 9756656,
     * prof4 -> 4, resp6984505 -> 6984505). Per AMD's API docs, these prefixes only label
     * the object type in outbound/response payloads — inbound financial requests expect
     * the bare numeric ID.
     */
    private function stripAmdIdPrefix(string $id): string
    {
        return preg_replace('/^\D+/', '', $id) ?? $id;
    }

    /**
     * Post a patient payment against one or more charges.
     *
     * $patientPayload must match the AMD addpayments patient structure:
     *   @patientid, @amount, @paycode, @paymethod, @checknumber, chargelist, etc.
     * Populate it from getChargeDetail data so all required balance fields are present.
     *
     * Payment method codes: 3=Visa, 4=MC, 5=Discover, 6=Amex, 7=OtherCard, 15=ACH/EFT
     */
    public function postPayment(AdvancedMdPractice $practice, array $patientPayload): array
    {
        $xml = $this->xmlRpc($practice, [
            '@action'      => 'addpayments',
            '@class'       => 'paymententry',
            '@msgtime'     => now()->format('m/d/Y g:i:s A'),
            '@useopenedge' => '0',
            '@checkout'    => '0',
            '@date'        => '',
            'patient'      => $patientPayload,
        ]);

        return $this->xmlToArray($xml);
    }

    /**
     * Post a patient payment against one or more charges.
     *
     * $patientPayload must match the AMD addpayments patient structure:
     *   @patientid, @amount, @paycode, @paymethod, @checknumber, chargelist, etc.
     * Populate it from getChargeDetail data so all required balance fields are present.
     *
     * Payment method codes: 3=Visa, 4=MC, 5=Discover, 6=Amex, 7=OtherCard, 15=ACH/EFT
     */
    public function recordPayment(AdvancedMdPractice $practice, array $patientPayload): array
    {
        $this->rateLimiter->throttle($practice->office_key, 'transaction_payments');

        try {

            Log::info('AdvancedMD Outbound Payload Debug', [
                'practice_id' => $practice->id,
                'office_key'  => $practice->office_key,
                'url'         => $practice->rest_pm_url . '/transaction/payments',
                'patient_id'  => $patientPayload['patientId'] ?? 'Missing',
                'raw_body'    => json_encode($patientPayload),
            ]);

            $response = Http::withToken($practice->session_token)
                ->acceptJson()
                ->post(
                    $practice->rest_pm_url . "/transaction/payments",
                    $patientPayload
                );

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 429) {
                Log::warning('AdvancedMD rate limited (HTTP 429)', [
                    'office_key'  => $practice->office_key,
                    'action'      => 'transaction_payments',
                    'retry_after' => $response->header('Retry-After'),
                ]);
            }

            Log::warning('AdvancedMD Payment Error', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new Exception(
                $response->json('message')
                    ?? 'Payment creation failed.'
            );

        } catch (Exception $e) {

            Log::warning('PMS Exception', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function xmlRpc(AdvancedMdPractice $practice, array $msg): SimpleXMLElement
    {
        $action = (string) ($msg['@action'] ?? 'unknown');
        $this->rateLimiter->throttle($practice->office_key, $action);

        try {
            // Do NOT send Accept: application/json — AMD XML-RPC endpoints always return XML.
            $responseBody = Http::withHeader('Cookie', 'token=' . $practice->session_token)
                ->withBody(json_encode(['ppmdmsg' => $msg]), 'application/json')
                ->post($practice->xmlrpc_url)
                ->throw()
                ->body();
        } catch (RequestException $e) {
            throw $this->wrapIfRateLimited($e, $practice->office_key, $action);
        }

        $xml = simplexml_load_string($responseBody);

        if ($xml === false) {
            throw new RuntimeException('AdvancedMD XML-RPC returned non-parseable XML.');
        }

        return $xml;
    }

    /**
     * AMD returns a plain HTTP 429 when a call would exceed its per-office-key
     * rate limit. Surface the Retry-After it sends back instead of letting this
     * look like an auth/XML-parsing failure (the two most common causes we log).
     */
    private function wrapIfRateLimited(RequestException $e, string $officeKey, string $action): \Throwable
    {
        if ($e->response->status() !== 429) {
            return $e;
        }

        $retryAfter = $e->response->header('Retry-After');

        Log::warning('AdvancedMD rate limited (HTTP 429)', [
            'office_key'  => $officeKey,
            'action'      => $action,
            'retry_after' => $retryAfter,
        ]);

        return new RuntimeException(
            "AdvancedMD rate limit hit for office {$officeKey} (action: {$action})"
            . ($retryAfter ? "; retry after {$retryAfter}s." : '.')
        );
    }

    /**
     * Recursively convert a SimpleXMLElement to a plain PHP array.
     * XML attributes become "@key" entries; bare text content becomes "_value".
     */
    private function xmlToArray(SimpleXMLElement $element): array
    {
        $array = [];

        foreach ($element->attributes() as $key => $value) {
            $array['@' . $key] = (string) $value;
        }

        $children = iterator_to_array($element->children(), true);

        if (empty($children)) {
            $text = trim((string) $element);
            if ($text !== '') {
                $array['_value'] = $text;
            }
            return $array;
        }

        foreach ($element->children() as $name => $child) {
            $parsed = $this->xmlToArray($child);
            if (array_key_exists($name, $array)) {
                if (! isset($array[$name][0]) || ! is_array($array[$name][0])) {
                    $array[$name] = [$array[$name]];
                }
                $array[$name][] = $parsed;
            } else {
                $array[$name] = $parsed;
            }
        }

        return $array;
    }
}
