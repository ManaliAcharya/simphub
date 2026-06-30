<?php

if (! function_exists('clientConfigUrl')) {
    function clientConfigUrl($client): string
    {
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

            default => "",
        };
    }
}
