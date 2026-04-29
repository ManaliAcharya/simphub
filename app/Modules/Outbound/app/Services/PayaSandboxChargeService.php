<?php

namespace Modules\Outbound\Services;

use DOMDocument;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\DTOs\ChargeRequest;
use RuntimeException;
use SoapClient;
use SoapHeader;

class PayaSandboxChargeService
{
    public function charge(ChargeRequest $request, array $credentials = []): array
    {
        $config = $this->resolveConfig($credentials);
        $paymentInfo = (object) array_merge($this->defaultPaymentInfo(), $credentials['payment_info'] ?? []);
        $paymentInfo->RequestID = 'R'.now()->format('ymdHis').random_int(111, 999);
        $paymentInfo->TransactionID = 'T'.now()->format('ymdHis').random_int(111, 999);
        $amount = '-'.number_format($request->amountInCents / 100, 2, '.', '');

        $client = $this->makeSoapClient($config);
        $xml = $this->makeDataPacket($paymentInfo, $amount, $config['terminal_id']);

        $terminalSettingsMethod = $config['terminal_settings_method'] ?: 'GetCertificationTerminalSettings';
        $processMethod = $config['process_method'] ?: 'ProcessSingleCertificationCheck';

        $settingsResult = $client->__soapCall($terminalSettingsMethod, []);
        $settingsXml = (string) ($settingsResult->{$terminalSettingsMethod.'Result'} ?? '');
        $this->logSoapExchange('Paya certification request', $client, $config, [
            'method' => $terminalSettingsMethod,
            'amount' => $amount,
            'request_id' => $paymentInfo->RequestID,
            'transaction_id' => $paymentInfo->TransactionID,
        ]);

        if (! $this->isCertified($settingsXml)) {
            throw new RuntimeException($this->certificationFailureMessage($settingsXml));
        }

        $processResult = $client->__soapCall($processMethod, [[
            'DataPacket' => $xml,
        ]]);
        $this->logSoapExchange('Paya process request', $client, $config, [
            'method' => $processMethod,
            'amount' => $amount,
            'request_id' => $paymentInfo->RequestID,
            'transaction_id' => $paymentInfo->TransactionID,
            'data_packet' => $xml,
        ]);

        $rawXml = (string) ($processResult->{$processMethod.'Result'} ?? '');
        if ($rawXml === '') {
            throw new RuntimeException('Paya gateway returned an empty response.');
        }

        $parsed = simplexml_load_string($rawXml);
        if (! $parsed) {
            throw new RuntimeException('Paya gateway response could not be parsed.');
        }

        $resultCode = (string) ($parsed->AUTHORIZATION_MESSAGE->RESULT_CODE ?? '');
        $transactionId = (string) ($parsed->AUTHORIZATION_MESSAGE->TRANSACTION_ID ?? '');
        $message = (string) ($parsed->AUTHORIZATION_MESSAGE->MESSAGE ?? $parsed->VALIDATION_MESSAGE->VALIDATION_ERROR->MESSAGE ?? 'Payment was declined.');

        return [
            'approved' => $resultCode === '0',
            'result_code' => $resultCode,
            'transaction_id' => $transactionId,
            'message' => $message,
            'response_type' => (string) ($parsed->AUTHORIZATION_MESSAGE->RESPONSE_TYPE ?? ''),
            'response_type_text' => (string) ($parsed->AUTHORIZATION_MESSAGE->RESPONSE_TYPE_TEXT ?? ''),
            'type_code' => (string) ($parsed->AUTHORIZATION_MESSAGE->TYPE_CODE ?? ''),
            'code' => (string) ($parsed->AUTHORIZATION_MESSAGE->CODE ?? ''),
            'request_id' => (string) ($parsed['REQUEST_ID'] ?? ''),
            'identifier' => (string) ($paymentInfo->Identifier ?? 'A'),
            'raw_xml' => $rawXml,
        ];
    }

    private function makeSoapClient(array $config): SoapClient
    {
        $client = new SoapClient(base_path('paya_payment/AuthGatewayWSDL-Demo.eftchecks.com.xml'), [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);

        $header = new SoapHeader($config['namespace'], 'AuthGatewayHeader', [
            'UserName' => $config['username'],
            'Password' => $config['password'],
            'TerminalID' => $config['terminal_id'],
        ]);

        $client->__setSoapHeaders([$header]);

        return $client;
    }

    private function makeDataPacket(object $paymentInfo, string $amount, string $terminalId): string
    {
        $dom = new DOMDocument('1.0', 'ISO-8859-1');
        $dom->formatOutput = true;

        $auth = $dom->createElement('AUTH_GATEWAY');
        $auth->appendChild(new \DOMAttr('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance'));
        $auth->appendChild(new \DOMAttr('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema'));
        $auth->appendChild(new \DOMAttr('REQUEST_ID', $paymentInfo->RequestID));

        $transaction = $dom->createElement('TRANSACTION');
        $transaction->appendChild($dom->createElement('TRANSACTION_ID', $paymentInfo->TransactionID));

        $merchant = $dom->createElement('MERCHANT');
        $merchant->appendChild($dom->createElement('TERMINAL_ID', $terminalId));
        $transaction->appendChild($merchant);

        $packet = $dom->createElement('PACKET');
        $packet->appendChild($dom->createElement('IDENTIFIER', (string) ($paymentInfo->Identifier ?? 'A')));

        $account = $dom->createElement('ACCOUNT');
        $account->appendChild($dom->createElement('ROUTING_NUMBER', $paymentInfo->RoutingNumber));
        $account->appendChild($dom->createElement('ACCOUNT_NUMBER', $paymentInfo->AccountNumber));
        $account->appendChild($dom->createElement('ACCOUNT_TYPE', 'Checking'));
        $packet->appendChild($account);

        $consumer = $dom->createElement('CONSUMER');
        foreach ([
            'FIRST_NAME' => $paymentInfo->FirstName,
            'LAST_NAME' => $paymentInfo->LastName,
            'ADDRESS1' => $paymentInfo->Address1,
            'ADDRESS2' => $paymentInfo->Address2,
            'CITY' => $paymentInfo->City,
            'STATE' => $paymentInfo->State,
            'ZIP' => $paymentInfo->Zip,
            'PHONE_NUMBER' => $paymentInfo->PhoneNumber,
            'DL_STATE' => $paymentInfo->DLState,
            'DL_NUMBER' => $paymentInfo->DLNumber,
        ] as $name => $value) {
            $consumer->appendChild($dom->createElement($name, (string) $value));
        }
        $consumer->appendChild($dom->createElement('COURTESY_CARD_ID'));
        $packet->appendChild($consumer);

        $check = $dom->createElement('CHECK');
        $check->appendChild($dom->createElement('CHECK_AMOUNT', $amount));
        $packet->appendChild($check);

        $transaction->appendChild($packet);
        $auth->appendChild($transaction);

        return $dom->saveXML($auth);
    }

    private function isCertified(string $xml): bool
    {
        if ($xml === '') {
            return false;
        }

        $parsed = simplexml_load_string($xml);

        return $parsed !== false && ! isset($parsed->EXCEPTION);
    }

    private function resolveConfig(array $credentials): array
    {
        return [
            'wsdl' => $this->credentialValue($credentials, 'wsdl', 'PAYA_WSDL_PATH', 'C:\\laragon\\www\\payment-middleware\\paya_payment\\AuthGatewayWSDL-Demo.eftchecks.com.xml'),
            'username' => $this->credentialValue($credentials, 'username', 'PAYA_USERNAME', 'ImpactPaysCert'),
            'password' => $this->credentialValue($credentials, 'password', 'PAYA_PASSWORD', '4AA3ZNSk#gpFbe9Z'),
            'terminal_id' => $this->credentialValue($credentials, 'terminal_id', 'PAYA_TERMINAL_ID', '1814'),
            'namespace' => $this->normalizeNamespace(
                $this->credentialValue($credentials, 'namespace', 'PAYA_NAMESPACE', 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway')
            ),
            'terminal_settings_method' => $this->credentialValue($credentials, 'terminal_settings_method', 'PAYA_TERMINAL_SETTINGS_METHOD', 'GetCertificationTerminalSettings'),
            'process_method' => $this->credentialValue($credentials, 'process_method', 'PAYA_PROCESS_METHOD', 'ProcessSingleCertificationCheck'),
            'sources' => [
                'wsdl' => $this->credentialSource($credentials, 'wsdl'),
                'username' => $this->credentialSource($credentials, 'username'),
                'password' => $this->credentialSource($credentials, 'password'),
                'terminal_id' => $this->credentialSource($credentials, 'terminal_id'),
                'namespace' => $this->credentialSource($credentials, 'namespace'),
                'terminal_settings_method' => $this->credentialSource($credentials, 'terminal_settings_method'),
                'process_method' => $this->credentialSource($credentials, 'process_method'),
            ],
        ];
    }

    private function defaultPaymentInfo(): array
    {
        return [
            'RoutingNumber' => env('PAYA_ROUTING_NUMBER', '490000018'),
            'AccountNumber' => env('PAYA_ACCOUNT_NUMBER', '123456789'),
            'CheckNumber' => env('PAYA_CHECK_NUMBER', '11111'),
            'FirstName' => env('PAYA_FIRST_NAME', 'Sandbox'),
            'LastName' => env('PAYA_LAST_NAME', 'Payer'),
            'Address1' => env('PAYA_ADDRESS1', '123 Demo Street'),
            'Address2' => env('PAYA_ADDRESS2', 'Suite 100'),
            'City' => env('PAYA_CITY', 'Memphis'),
            'State' => env('PAYA_STATE', 'TN'),
            'Zip' => env('PAYA_ZIP', '38103'),
            'PhoneNumber' => env('PAYA_PHONE_NUMBER', '9015551212'),
            'DLState' => env('PAYA_DL_STATE', 'TN'),
            'DLNumber' => env('PAYA_DL_NUMBER', '12345'),
            'Identifier' => env('PAYA_IDENTIFIER', 'A'),
        ];
    }

    private function credentialValue(array $credentials, string $key, string $envKey, string $default): string
    {
        $value = $credentials[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return (string) env($envKey, $default);
    }

    private function credentialSource(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return 'mid_credentials';
        }

        return 'env';
    }

    private function certificationFailureMessage(string $settingsXml): string
    {
        if ($settingsXml === '') {
            return 'Paya certification terminal settings failed: empty response from gateway.';
        }

        $parsed = simplexml_load_string($settingsXml);

        if ($parsed === false) {
            return 'Paya certification terminal settings failed: invalid XML returned by gateway.';
        }

        $message = (string) ($parsed->EXCEPTION->MESSAGE ?? $parsed->EXCEPTION->ERROR_MESSAGE ?? '');
        $code = (string) ($parsed->EXCEPTION->CODE ?? '');

        if ($message !== '') {
            return 'Paya certification terminal settings failed: '.$message.($code !== '' ? " ({$code})" : '');
        }

        return 'Paya certification terminal settings failed.';
    }

    private function logSoapExchange(string $label, SoapClient $client, array $config, array $context = []): void
    {
        Log::info($label, [
            ...$context,
            'wsdl' => $config['wsdl'],
            'namespace' => $config['namespace'],
            'username' => $config['username'],
            'password' => $this->maskSecret($config['password']),
            'terminal_id' => $config['terminal_id'],
            'sources' => $config['sources'] ?? [],
            'soap_request_headers' => method_exists($client, '__getLastRequestHeaders') ? $client->__getLastRequestHeaders() : null,
            'soap_request_xml' => method_exists($client, '__getLastRequest') ? $client->__getLastRequest() : null,
            'soap_response_headers' => method_exists($client, '__getLastResponseHeaders') ? $client->__getLastResponseHeaders() : null,
            'soap_response_xml' => method_exists($client, '__getLastResponse') ? $client->__getLastResponse() : null,
        ]);
    }

    private function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '[empty]';
        }

        if (strlen($secret) <= 4) {
            return str_repeat('*', strlen($secret));
        }

        return substr($secret, 0, 2).str_repeat('*', max(0, strlen($secret) - 4)).substr($secret, -2);
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim($namespace);

        if ($namespace === '') {
            return 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway';
        }

        foreach ([
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
