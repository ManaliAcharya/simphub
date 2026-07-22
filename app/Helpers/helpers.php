<?php

if (! function_exists('clientConfigUrl')) {
    function clientConfigUrl($owner): string
    {
        if ($owner instanceof \Modules\Boarding\Models\BoardingClient) {
            return route('inbound.boarding.page');
        }

        $client = $owner;

        return match (strtolower($client->client_pms)) {
            'custom' => route('inbound.clients.api-docs', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'quickbooks' => route('inbound.quickbooks.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'wave' => route('inbound.wave.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'zoho' => route('inbound.zoho.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'clio' => route('inbound.clio.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'lawcus' => route('inbound.lawcus.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'advancedmd' => route('inbound.advancedmd.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            'mindbody' => route('inbound.mindbody.page', [
                'pms_client_id' => $client->pms_client_id,
            ]),

            default => "",
        };
    }
}

if (! function_exists('getClientIp')) {

    /**
     * Get real visitor IP.
     * Supports Cloudflare proxy.
     */
    function getClientIp(?\Illuminate\Http\Request $request = null): ?string
    {
        $request = $request ?: request();

        if ($ip = $request->header('CF-Connecting-IP')) {
            return trim($ip);
        }

        if ($forwardedFor = $request->header('X-Forwarded-For')) {
            return trim(explode(',', $forwardedFor)[0]);
        }

        return $request->ip();
    }
}
