<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\AdvancedMdPractice;
use RuntimeException;
use SimpleXMLElement;

class AdvancedMdSessionService
{
    private const PARTNER_LOGIN_URL = 'https://partnerlogin.advancedmd.com/practicemanager/xmlrpc/processrequest.aspx';

    public function ensureValidSession(AdvancedMdPractice $practice): AdvancedMdPractice
    {
        if (! $practice->isSessionExpired()) {
            return $practice;
        }

        return $this->login($practice);
    }

    public function login(AdvancedMdPractice $practice): AdvancedMdPractice
    {
        $loginBody = $this->buildLoginBody($practice);

        // Step 1: Hit the fixed partner-login endpoint to get the per-account redirect URL.
        $step1Xml = $this->postAndParseXml(self::PARTNER_LOGIN_URL, $loginBody, 'step 1 partner login');
        $webserver = (string) ($step1Xml->Results->usercontext['webserver'] ?? '');

        if ($webserver === '') {
            $reason = $this->extractAmdErrorMessage($step1Xml);
            Log::warning('AdvancedMD partner login did not return a webserver URL', [
                'practice_id'  => $practice->id,
                'office_key'   => $practice->office_key,
                'amd_reason'   => $reason,
                'raw_response' => $step1Xml->asXML(),
            ]);

            throw new RuntimeException($reason ?? 'Unable to connect to AdvancedMD. Please double-check your Office Key, App Name, Username, and Password and try again.');
        }

        $urls = $this->parseWebserverUrl($webserver);

        // Step 2: Hit the redirected XMLRPC URL with the same credentials to get the session token.
        $step2Xml = $this->postAndParseXml($urls['xmlrpc'], $loginBody, 'step 2 redirect login');
        $token = (string) ($step2Xml->Results->usercontext ?? '');

        if ($token === '') {
            $reason = $this->extractAmdErrorMessage($step2Xml);
            Log::warning('AdvancedMD redirect login did not return a session token', [
                'practice_id'  => $practice->id,
                'office_key'   => $practice->office_key,
                'xmlrpc_url'   => $urls['xmlrpc'],
                'amd_reason'   => $reason,
                'raw_response' => $step2Xml->asXML(),
            ]);

            throw new RuntimeException($reason ?? 'Unable to connect to AdvancedMD. Please double-check your Office Key, App Name, Username, and Password and try again.');
        }

        $practice->forceFill([
            'session_token'      => $token,
            'session_expires_at' => now()->addHours(24),
            'xmlrpc_url'         => $urls['xmlrpc'],
            'rest_pm_url'        => $urls['rest_pm'],
            'last_error'         => null,
        ])->save();

        return $practice->fresh();
    }

    /**
     * AMD returns an <EXCEPTION>/<ERROR> style node with a human-readable reason when
     * login fails (e.g. bad credentials, wrong office code) instead of a session token.
     * Surface that instead of our own generic message whenever AMD actually tells us why.
     */
    private function extractAmdErrorMessage(SimpleXMLElement $xml): ?string
    {
        $candidates = [
            (string) ($xml->Results->EXCEPTION->MESSAGE ?? ''),
            (string) ($xml->Results->EXCEPTION->ERROR_MESSAGE ?? ''),
            (string) ($xml->EXCEPTION->MESSAGE ?? ''),
            (string) ($xml->Results->ERROR ?? ''),
            (string) ($xml->ERROR ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function buildLoginBody(AdvancedMdPractice $practice): array
    {
        return [
            'ppmdmsg' => [
                '@action'     => 'login',
                '@class'      => 'login',
                '@msgtime'    => now()->format('m/d/Y g:i:s A'),
                '@username'   => $practice->username(),
                '@psw'        => $practice->password(),
                '@officecode' => $practice->office_key,
                '@appname'    => $practice->app_name,
            ],
        ];
    }

    /**
     * Derive XMLRPC and REST PM base URLs from the webserver string returned by
     * the partner login. Mirrors the JavaScript logic in the Postman test script:
     *
     *   baseURL  = "https://" + parts[2]
     *   host     = parts[-2]
     *   appName  = parts[-1]
     *   XMLRPC   = baseURL + "/" + parts[3] + "/" + host + "/" + appName + "/xmlrpc/processrequest.aspx"
     *   RESTPM   = baseURL + "/api/" + host + "/" + appName
     */
    private function parseWebserverUrl(string $webserver): array
    {
        $parts   = explode('/', $webserver);
        $baseUrl = 'https://' . $parts[2];
        $seg3    = $parts[3] ?? 'practicemanager';
        $host    = $parts[count($parts) - 2] ?? '';
        $appName = $parts[count($parts) - 1] ?? '';

        return [
            'xmlrpc'  => "{$baseUrl}/{$seg3}/{$host}/{$appName}/xmlrpc/processrequest.aspx",
            'rest_pm' => "{$baseUrl}/api/{$host}/{$appName}",
        ];
    }

    private function postAndParseXml(string $url, array $body, string $context): SimpleXMLElement
    {
        $responseBody = Http::withHeaders(['Accept' => 'text/xml'])
            ->withBody(json_encode($body), 'application/json')
            ->post($url)
            ->throw()
            ->body();

        $xml = simplexml_load_string($responseBody);

        if ($xml === false) {
            throw new RuntimeException("AdvancedMD {$context} returned invalid XML.");
        }

        return $xml;
    }
}
