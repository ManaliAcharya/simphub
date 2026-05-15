<?php

namespace Modules\Outbound\Services;

use RuntimeException;

class PayaTokenizerService
{
    public function tokenize(array $bankDetails): string
    {
        $payload = [
            'routing_number' => (string) ($bankDetails['routing_number'] ?? ''),
            'account_number' => (string) ($bankDetails['account_number'] ?? ''),
            'account_type'   => strtolower((string) ($bankDetails['account_type'] ?? 'checking')),
            'first_name'     => (string) ($bankDetails['first_name']     ?? ''),
            'last_name'      => (string) ($bankDetails['last_name']      ?? ''),
            'address1'       => (string) ($bankDetails['address1']       ?? ''),
            'city'           => (string) ($bankDetails['city']           ?? ''),
            'state'          => (string) ($bankDetails['state']          ?? ''),
            'zip'            => (string) ($bankDetails['zip']            ?? ''),
            'phone_number'   => (string) ($bankDetails['phone_number']   ?? ''),
        ];

        return encrypt(json_encode($payload));
    }

    public function detokenize(string $token): array
    {
        try {
            $decoded = json_decode(decrypt($token), true);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid or tampered payment token.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid payment token payload.');
        }

        return $decoded;
    }
}
