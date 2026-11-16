<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\ClioConnection;
use Throwable;

/**
 * The client's email is stored when the bill arrives. A merchant who adds a missing email
 * in Clio and clicks Resend expects it to be used, and editing a contact sends no bill
 * webhook — so Resend reads the contact's primary email from Clio first.
 */
class ClioRecipientRefreshService
{
    public function __construct(
        private readonly ClioApiClient $client,
        private readonly ClioOAuthService $oauth,
    ) {}

    /** Updates the invoice's recipients from Clio; leaves them as they were if Clio has none. */
    public function refresh(Invoice $invoice): Invoice
    {
        if ((string) $invoice->pms_source !== 'clio' || trim((string) $invoice->external_client_id) === '') {
            return $invoice;
        }

        try {
            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            if (! $connection) {
                return $invoice;
            }

            $contact = $this->client->fetchContact($this->oauth->ensureValidAccessToken($connection), (string) $invoice->external_client_id);
            $email   = trim((string) Arr::get($contact, 'data.primary_email_address', ''));
        } catch (Throwable $e) {
            Log::warning('Clio contact email refresh before Resend failed; using the stored email', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return $invoice;
        }

        if ($email !== '' && (array) ($invoice->recipient_emails ?? []) !== [$email]) {
            $invoice->forceFill(['recipient_emails' => [$email]])->save();
        }

        return $invoice;
    }
}
