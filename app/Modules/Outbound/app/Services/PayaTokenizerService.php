<?php

namespace Modules\Outbound\Services;

use DOMDocument;
use RuntimeException;
use SoapClient;
use SoapHeader;

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

    public function tokenizeViaPaya(array $bankDetails, array $credentials = []): string
    {
        $config        = $this->resolveConfig($credentials);
        $requestId     = 'R' . now()->format('ymdHis') . random_int(111, 999);
        $transactionId = 'T' . now()->format('ymdHis') . random_int(111, 999);

        $client = $this->makeSoapClient($config);
        $xml    = $this->makeTokenDataPacket($bankDetails, $requestId, $transactionId, $config['terminal_id']);
        $method = $config['token_method'] ?: 'GetCertificationToken';

        $result = $client->__soapCall($method, [['DataPacket' => $xml]]);
        $rawXml = (string) ($result->{$method . 'Result'} ?? '');

        if ($rawXml === '') {
            throw new RuntimeException('Paya tokenization returned an empty response.');
        }

        $parsed = simplexml_load_string($rawXml);
        if (! $parsed) {
            throw new RuntimeException('Paya tokenization response could not be parsed.');
        }

        $token = (string) ($parsed->TOKEN ?? '');

        if ($token === '') {
            $message = (string) (
                $parsed->EXCEPTION->MESSAGE
                ?? $parsed->VALIDATION_MESSAGE->VALIDATION_ERROR->MESSAGE
                ?? 'Tokenization failed.'
            );
            throw new RuntimeException('Paya tokenization failed: ' . $message);
        }

        return $token;
    }

    private function makeTokenDataPacket(array $bankDetails, string $requestId, string $transactionId, string $terminalId): string
    {
        $dom = new DOMDocument('1.0', 'ISO-8859-1');
        $dom->formatOutput = true;

        $auth = $dom->createElement('AUTH_GATEWAY');
        $auth->appendChild(new \DOMAttr('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance'));
        $auth->appendChild(new \DOMAttr('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema'));
        $auth->appendChild(new \DOMAttr('REQUEST_ID', $requestId));

        $transaction = $dom->createElement('TRANSACTION');
        $transaction->appendChild($dom->createElement('TRANSACTION_ID', $transactionId));

        $merchant = $dom->createElement('MERCHANT');
        $merchant->appendChild($dom->createElement('TERMINAL_ID', $terminalId));
        $transaction->appendChild($merchant);

        $packet = $dom->createElement('PACKET');

        $account = $dom->createElement('ACCOUNT');
        $account->appendChild($dom->createElement('ROUTING_NUMBER', (string) ($bankDetails['routing_number'] ?? '')));
        $account->appendChild($dom->createElement('ACCOUNT_NUMBER', (string) ($bankDetails['account_number'] ?? '')));
        $accountType = ucfirst(strtolower((string) ($bankDetails['account_type'] ?? 'checking')));
        $account->appendChild($dom->createElement('ACCOUNT_TYPE', $accountType));
        $packet->appendChild($account);

        $transaction->appendChild($packet);
        $auth->appendChild($transaction);
        $dom->appendChild($auth);

        return $dom->saveXML($auth);
    }

    private function makeConsumerElement(DOMDocument $dom): \DOMElement
    {
        $info     = $this->defaultConsumerInfo();
        $consumer = $dom->createElement('CONSUMER');

        foreach ([
            'FIRST_NAME'   => $info['FirstName'],
            'LAST_NAME'    => $info['LastName'],
            'ADDRESS1'     => $info['Address1'],
            'ADDRESS2'     => $info['Address2'],
            'CITY'         => $info['City'],
            'STATE'        => $info['State'],
            'ZIP'          => $info['Zip'],
            'PHONE_NUMBER' => $info['PhoneNumber'],
            'DL_STATE'     => $info['DLState'],
            'DL_NUMBER'    => $info['DLNumber'],
        ] as $name => $value) {
            $consumer->appendChild($dom->createElement($name, (string) $value));
        }
        $consumer->appendChild($dom->createElement('COURTESY_CARD_ID'));

        return $consumer;
    }

    private function makeSoapClient(array $config): SoapClient
    {
        $wsdl = ($config['environment'] ?? 'sandbox') === 'production'
            ? base_path('paya_payment/AuthGatewayWSDL-GetiGateway.eftchecks.com.xml')
            : base_path('paya_payment/AuthGatewayWSDL-Demo.eftchecks.com.xml');

        $client = new SoapClient($wsdl, [
            'trace'      => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);

        $header = new SoapHeader($config['namespace'], 'AuthGatewayHeader', [
            'UserName'   => $config['username'],
            'Password'   => $config['password'],
            'TerminalID' => $config['terminal_id'],
        ]);

        $client->__setSoapHeaders([$header]);

        return $client;
    }

    private function resolveConfig(array $credentials): array
    {
        $isProduction = ($credentials['environment'] ?? 'sandbox') === 'production';

        return [
            'environment'  => $isProduction ? 'production' : 'sandbox',
            'username'     => (string) ($credentials['username']    ?? ''),
            'password'     => (string) ($credentials['password']    ?? ''),
            'terminal_id'  => (string) ($credentials['terminal_id'] ?? ''),
            'namespace'    => $this->normalizeNamespace(
                (string) ($credentials['namespace'] ?? 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway')
            ),
            'token_method' => $isProduction ? 'GetToken' : 'GetCertificationToken',
        ];
    }

    private function defaultConsumerInfo(): array
    {
        return [
            'FirstName'   => (string) env('PAYA_FIRST_NAME', 'Sandbox'),
            'LastName'    => (string) env('PAYA_LAST_NAME', 'Payer'),
            'Address1'    => (string) env('PAYA_ADDRESS1', '123 Demo Street'),
            'Address2'    => (string) env('PAYA_ADDRESS2', 'Suite 100'),
            'City'        => (string) env('PAYA_CITY', 'Memphis'),
            'State'       => (string) env('PAYA_STATE', 'TN'),
            'Zip'         => (string) env('PAYA_ZIP', '38103'),
            'PhoneNumber' => (string) env('PAYA_PHONE_NUMBER', '9015551212'),
            'DLState'     => (string) env('PAYA_DL_STATE', 'TN'),
            'DLNumber'    => (string) env('PAYA_DL_NUMBER', '12345'),
        ];
    }

    private function credVal(array $credentials, string $key, string $envKey, string $default): string
    {
        $value = $credentials[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return (string) env($envKey, $default);
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim($namespace);

        if ($namespace === '') {
            return 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway';
        }

        foreach ([
            '/GetCertificationToken',
            '/GetToken',
            '/GetCertificationTerminalSettings',
            '/ProcessSingleCertificationCheck',
            '/GetTerminalSettings',
            '/ProcessSingleCheck',
        ] as $suffix) {
            if (str_ends_with($namespace, $suffix)) {
                return substr($namespace, 0, -strlen($suffix));
            }
        }

        return $namespace;
    }
}
