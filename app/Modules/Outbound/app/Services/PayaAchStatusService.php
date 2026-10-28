<?php

namespace Modules\Outbound\Services;

use DOMDocument;
use Modules\Outbound\DTOs\AchStatus;
use RuntimeException;
use SimpleXMLElement;
use SoapClient;
use SoapHeader;

/**
 * Looks up one Paya GETI ACH transaction by the TRANSACTION_ID Paya returned when it was
 * submitted, so PollAchSettlementsJob can tell whether it settled or came back returned.
 *
 * GETI has no webhooks, and the AuthGateway WSDL we charge through has no status call
 * (only GetToken / ProcessSingleCheck* / GetArchivedResponse / GetTerminalSettings).
 * Paya points to a ViewSingleRecord-style reporting call instead, so the WSDL and method
 * come from config (services.paya.status_*) and the lookup is off until both are set.
 *
 * The request packet and the response fields read below follow the AuthGateway XML
 * conventions; confirm them against Paya's sample request/response before enabling.
 */
class PayaAchStatusService
{
    private const STATUS_FIELDS = ['SETTLEMENT_STATUS', 'TRANSACTION_STATUS', 'STATUS', 'STATE'];

    private const RETURN_CODE_FIELDS = ['RETURN_CODE', 'RETURN_REASON_CODE', 'ACH_RETURN_CODE'];

    private const RETURN_REASON_FIELDS = ['RETURN_REASON', 'RETURN_DESCRIPTION', 'RETURN_MESSAGE'];

    public function isConfigured(): bool
    {
        return trim((string) config('services.paya.status_wsdl')) !== ''
            && trim((string) config('services.paya.status_method')) !== '';
    }

    public function lookup(string $transactionId, array $credentials): AchStatus
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paya ACH status lookup is not configured (PAYA_STATUS_WSDL / PAYA_STATUS_METHOD).');
        }

        if (trim($transactionId) === '') {
            throw new RuntimeException('Paya transaction has no TRANSACTION_ID to look up.');
        }

        $method = (string) config('services.paya.status_method');
        $client = $this->makeSoapClient($credentials);

        $result = $client->__soapCall($method, [['DataPacket' => $this->makeDataPacket($transactionId, $credentials)]]);
        $rawXml = (string) ($result->{$method.'Result'} ?? '');

        return $this->parseResponse($rawXml);
    }

    public function parseResponse(string $rawXml): AchStatus
    {
        if (trim($rawXml) === '') {
            throw new RuntimeException('Paya status lookup returned an empty response.');
        }

        $parsed = @simplexml_load_string($rawXml);
        if (! $parsed) {
            throw new RuntimeException('Paya status lookup response could not be parsed.');
        }

        if (isset($parsed->EXCEPTION)) {
            $message = (string) ($parsed->EXCEPTION->MESSAGE ?? $parsed->EXCEPTION->ERROR_MESSAGE ?? 'unknown error');

            throw new RuntimeException('Paya status lookup failed: '.$message);
        }

        $statusText   = strtoupper($this->firstField($parsed, self::STATUS_FIELDS));
        $returnCode   = strtoupper($this->firstField($parsed, self::RETURN_CODE_FIELDS));
        $returnReason = $this->firstField($parsed, self::RETURN_REASON_FIELDS);
        $raw          = ['status' => $statusText, 'raw_xml' => $rawXml];

        if (preg_match('/^R\d{2}$/', $returnCode) === 1
            || str_contains($statusText, 'RETURN')
            || str_contains($statusText, 'NSF')
            || str_contains($statusText, 'CHARGEBACK')) {
            return new AchStatus(AchStatus::RETURNED, $returnCode ?: null, $returnReason ?: null, $raw);
        }

        foreach (['SETTLE', 'FUNDED', 'DEPOSITED', 'CLEARED'] as $settled) {
            if (str_contains($statusText, $settled)) {
                return new AchStatus(AchStatus::SETTLED, raw: $raw);
            }
        }

        return new AchStatus(AchStatus::PENDING, raw: $raw);
    }

    private function firstField(SimpleXMLElement $xml, array $names): string
    {
        foreach ($names as $name) {
            $nodes = $xml->xpath("//*[local-name()='{$name}']");

            if (! empty($nodes) && trim((string) $nodes[0]) !== '') {
                return trim((string) $nodes[0]);
            }
        }

        return '';
    }

    private function makeDataPacket(string $transactionId, array $credentials): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $auth = $dom->createElement('AUTH_GATEWAY');
        $auth->appendChild(new \DOMAttr('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance'));
        $auth->appendChild(new \DOMAttr('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema'));

        $transaction = $dom->createElement('TRANSACTION');
        $transaction->appendChild($dom->createElement('TRANSACTION_ID', htmlspecialchars($transactionId, ENT_XML1)));
        $transaction->appendChild($dom->createElement('TERMINAL_ID', htmlspecialchars((string) ($credentials['terminal_id'] ?? ''), ENT_XML1)));
        $auth->appendChild($transaction);

        return $dom->saveXML($auth);
    }

    private function makeSoapClient(array $credentials): SoapClient
    {
        $client = new SoapClient((string) config('services.paya.status_wsdl'), [
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);

        $namespace = trim((string) ($credentials['namespace'] ?? '')) ?: 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway';

        $client->__setSoapHeaders([new SoapHeader($namespace, 'AuthGatewayHeader', [
            'UserName'   => (string) ($credentials['username'] ?? ''),
            'Password'   => (string) ($credentials['password'] ?? ''),
            'TerminalID' => (string) ($credentials['terminal_id'] ?? ''),
        ])]);

        return $client;
    }
}
