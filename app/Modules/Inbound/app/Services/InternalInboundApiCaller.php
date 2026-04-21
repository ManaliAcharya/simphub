<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InternalInboundApiCaller
{
    public function callInvoiceIngestion(string $source, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $request = Request::create(
            uri: "/api/v1/inbound/invoices/{$source}",
            method: 'POST',
            content: $body,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        $response = app()->handle($request);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(), true);

        if ($status >= Response::HTTP_BAD_REQUEST) {
            Log::error('Internal inbound invoice API call failed.', [
                'source' => $source,
                'status' => $status,
                'payload' => $payload,
                'response' => $data,
            ]);

            throw new RuntimeException(
                Arr::get($data, 'message', 'Internal invoice ingestion API call failed.')
            );
        }

        return is_array($data) ? $data : [];
    }
}
