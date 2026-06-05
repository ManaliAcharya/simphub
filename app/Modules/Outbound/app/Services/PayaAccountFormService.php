<?php

namespace Modules\Outbound\Services;

use RuntimeException;

class PayaAccountFormService
{
    /**
     * Generate a signed Paya AccountForm URL for embedding as an iframe.
     *
     * Required env vars:
     *   PAYA_DEVELOPER_ID   — developer account ID from Paya
     *   PAYA_USER_HASH_KEY  — hash key from Paya developer settings
     *   PAYA_LOCATION_ID    — merchant location ID from Paya
     *   PAYA_ACCOUNTFORM_URL — base URL (sandbox or production)
     *
     * @param  array  $midCredentials  Decrypted mid_credentials for the routing rule
     * @param  array  $invoiceMeta     Optional invoice metadata (invoice_number, customer name, etc.)
     */
    public function generateUrl(array $midCredentials = [], array $invoiceMeta = []): string
    {
        $developerId  = env('PAYA_DEVELOPER_ID', '');
        $userHashKey  = env('PAYA_USER_HASH_KEY', '');
        $locationId   = env('PAYA_LOCATION_ID', '');
        $baseUrl      = env('PAYA_ACCOUNTFORM_URL', 'https://api.sandbox.payaconnect.com/v2/accountform');

        // Fall back to mid_credentials if env vars not set
        $userId = $midCredentials['username'] ?? env('PAYA_USERNAME', '');
        if (empty($userHashKey)) {
            $userHashKey = $midCredentials['password'] ?? '';
        }
        if (empty($locationId)) {
            $locationId = $midCredentials['location_id'] ?? $midCredentials['terminal_id'] ?? '';
        }

        if (empty($developerId)) {
            throw new RuntimeException('PAYA_DEVELOPER_ID is not configured. Please add it to your .env file.');
        }
        if (empty($userId)) {
            throw new RuntimeException('Paya user ID (username) is not configured.');
        }
        if (empty($userHashKey)) {
            throw new RuntimeException('PAYA_USER_HASH_KEY is not configured.');
        }

        $timestamp = time();

        // HMAC-SHA256(user_id + timestamp, user_hash_key)
        $hashKey = hash_hmac('sha256', $userId . $timestamp, $userHashKey);

        // AccountForm data payload
        $data = [
            'accountvault' => array_filter([
                'payment_method'      => 'ach',
                'location_id'         => $locationId ?: null,
                'title'               => 'ACH Payment' . (isset($invoiceMeta['invoice_number']) ? ' – #' . $invoiceMeta['invoice_number'] : ''),
                'account_holder_name' => $invoiceMeta['customer_name'] ?? null,
                'parent_send_message' => true,
                'parent_close'        => false,
            ]),
        ];

        // Hex-encode the JSON payload to prevent tampering
        $hexData = bin2hex(json_encode($data));

        return rtrim($baseUrl, '/') . '?' . http_build_query([
            'developer-id' => $developerId,
            'user-id'      => $userId,
            'hash-key'     => $hashKey,
            'timestamp'    => $timestamp,
            'data'         => $hexData,
        ]);
    }
}
