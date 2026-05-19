<?php

namespace Modules\Outbound\Services;

use DOMDocument;
use Modules\Outbound\DTOs\ChargeRequest;
use RuntimeException;
use SoapClient;
use SoapHeader;
use stdClass;

class PayaSandboxChargeService
{
    public function charge(ChargeRequest $request, array $credentials = []): array
    {
        $config    = $this->resolveConfig($credentials);
        $payaToken = trim((string) ($request->billing['paya_token'] ?? ''));

        if ($payaToken !== '') {
            return $this->chargeWithToken($request, $payaToken, $config, $credentials);
        }

        $paymentInfo = (object) array_merge(
            $this->defaultPaymentInfo(),
            $credentials['payment_info'] ?? [],
            [
                'RoutingNumber' => (string) ($request->billing['routing_number'] ?? ''),
                'AccountNumber' => (string) ($request->billing['account_number'] ?? ''),
            ]
        );

        if (trim((string) $paymentInfo->RoutingNumber) === '' || trim((string) $paymentInfo->AccountNumber) === '') {
            throw new RuntimeException('Payment cannot be done because account number and routing number are missing in invoice custom fields.');
        }

        $paymentInfo->RequestID    = 'R'.now()->format('ymdHis').random_int(111, 999);
        $paymentInfo->TransactionID = 'T'.now()->format('ymdHis').random_int(111, 999);
        $isCredit  = strtolower($request->transactionType) === 'credit';
        $identifier = $isCredit ? 'R' : 'A';
        $amount     = number_format($request->amountInCents / 100, 2, '.', '');

        $client = $this->makeSoapClient($config);
        $xml    = $this->makeDataPacket($paymentInfo, $amount, $identifier, $config['terminal_id']);

        $terminalSettingsMethod = $config['terminal_settings_method'] ?: 'GetCertificationTerminalSettings';
        $processMethod          = $config['process_method'] ?: 'ProcessSingleCertificationCheck';

        $settingsResult = $client->__soapCall($terminalSettingsMethod, []);
        $settingsXml    = (string) ($settingsResult->{$terminalSettingsMethod.'Result'} ?? '');

        if (! $this->isCertified($settingsXml)) {
            throw new RuntimeException($this->certificationFailureMessage($settingsXml));
        }

        \Log::debug('Paya direct charge request', ['method' => $processMethod, 'xml' => $xml]);
        $processResult = $client->__soapCall($processMethod, [['DataPacket' => $xml]]);
        $rawResult = (string) ($processResult->{$processMethod.'Result'} ?? '');
        \Log::debug('Paya direct charge response', ['raw' => $rawResult]);

        return $this->parseChargeResponse($rawResult, $paymentInfo->Identifier ?? 'A');
    }

    private function chargeWithToken(ChargeRequest $request, string $payaToken, array $config, array $credentials): array
    {
        $paymentInfo = (object) array_merge(
            $this->defaultPaymentInfo(),
            $credentials['payment_info'] ?? [],
        );
        $paymentInfo->RequestID    = 'R'.now()->format('ymdHis').random_int(111, 999);
        $paymentInfo->TransactionID = 'T'.now()->format('ymdHis').random_int(111, 999);
        $isCredit   = strtolower($request->transactionType) === 'credit';
        $identifier = $isCredit ? 'R' : 'A';
        $amount     = number_format($request->amountInCents / 100, 2, '.', '');

        $client = $this->makeSoapClient($config);
        $xml    = $this->makeTokenChargeDataPacket($payaToken, $paymentInfo, $amount, $identifier, $config['terminal_id']);

        $terminalSettingsMethod  = $config['terminal_settings_method'] ?: 'GetCertificationTerminalSettings';
        $processMethod           = ($config['process_method'] ?: 'ProcessSingleCertificationCheck') . 'WithToken';

        $settingsResult = $client->__soapCall($terminalSettingsMethod, []);
        $settingsXml    = (string) ($settingsResult->{$terminalSettingsMethod.'Result'} ?? '');

        if (! $this->isCertified($settingsXml)) {
            throw new RuntimeException($this->certificationFailureMessage($settingsXml));
        }

        $processResult = $client->__soapCall($processMethod, [['DataPacket' => $xml]]);

        return $this->parseChargeResponse(
            (string) ($processResult->{$processMethod.'Result'} ?? ''),
            $paymentInfo->Identifier ?? 'A',
        );
    }

    private function parseChargeResponse(string $rawXml, string $identifier): array
    {
        if ($rawXml === '') {
            throw new RuntimeException('Paya gateway returned an empty response.');
        }

        $parsed = simplexml_load_string($rawXml);
        if (! $parsed) {
            throw new RuntimeException('Paya gateway response could not be parsed.');
        }

        $resultCode    = (string) ($parsed->AUTHORIZATION_MESSAGE->RESULT_CODE ?? '');
        $transactionId = (string) ($parsed->AUTHORIZATION_MESSAGE->TRANSACTION_ID ?? '');
        $message       = (string) ($parsed->AUTHORIZATION_MESSAGE->MESSAGE ?? $parsed->VALIDATION_MESSAGE->VALIDATION_ERROR->MESSAGE ?? 'Payment was declined.');

        return [
            'approved'           => $resultCode === '0',
            'result_code'        => $resultCode,
            'transaction_id'     => $transactionId,
            'message'            => $message,
            'response_type'      => (string) ($parsed->AUTHORIZATION_MESSAGE->RESPONSE_TYPE ?? ''),
            'response_type_text' => (string) ($parsed->AUTHORIZATION_MESSAGE->RESPONSE_TYPE_TEXT ?? ''),
            'type_code'          => (string) ($parsed->AUTHORIZATION_MESSAGE->TYPE_CODE ?? ''),
            'code'               => (string) ($parsed->AUTHORIZATION_MESSAGE->CODE ?? ''),
            'request_id'         => (string) ($parsed['REQUEST_ID'] ?? ''),
            'identifier'         => $identifier,
            'raw_xml'            => $rawXml,
        ];
    }

    private function makeTokenChargeDataPacket(string $payaToken, object $paymentInfo, string $amount, string $identifier, string $terminalId): string
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
        $packet->appendChild($dom->createElement('IDENTIFIER', $identifier));

        $account = $dom->createElement('ACCOUNT');
        $account->appendChild($dom->createElement('TOKEN', $payaToken));
        $packet->appendChild($account);

        $consumer = $dom->createElement('CONSUMER');
        foreach ([
            'FIRST_NAME'   => $paymentInfo->FirstName,
            'LAST_NAME'    => $paymentInfo->LastName,
            'ADDRESS1'     => $paymentInfo->Address1,
            'ADDRESS2'     => $paymentInfo->Address2,
            'CITY'         => $paymentInfo->City,
            'STATE'        => $paymentInfo->State,
            'ZIP'          => $paymentInfo->Zip,
            'PHONE_NUMBER' => $paymentInfo->PhoneNumber,
            'DL_STATE'     => $paymentInfo->DLState,
            'DL_NUMBER'    => $paymentInfo->DLNumber,
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

    private function makeDataPacket(object $paymentInfo, string $amount, string $identifier, string $terminalId): string
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
        $packet->appendChild($dom->createElement('IDENTIFIER', $identifier));

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
            'username' => $this->credentialValue($credentials, 'username', 'PAYA_USERNAME', 'ImpactPaysCert'),
            'password' => '4AA3ZNSk#gpFbe9Z',
            'terminal_id' => $this->credentialValue($credentials, 'terminal_id', 'PAYA_TERMINAL_ID', '1814'),
            'namespace' => $this->normalizeNamespace(
                $this->credentialValue($credentials, 'namespace', 'PAYA_NAMESPACE', 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway')
            ),
            'terminal_settings_method' => $this->credentialValue($credentials, 'terminal_settings_method', 'PAYA_TERMINAL_SETTINGS_METHOD', 'GetCertificationTerminalSettings'),
            'process_method' => $this->credentialValue($credentials, 'process_method', 'PAYA_PROCESS_METHOD', 'ProcessSingleCertificationCheck'),
        ];
    }

    private function defaultPaymentInfo(): array
    {
        return [
            'RoutingNumber' => '',
            'AccountNumber' => '',
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
