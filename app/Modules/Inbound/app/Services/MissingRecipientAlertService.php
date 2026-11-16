<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Throwable;

/**
 * A bill that's ready to send but whose client has no email on file would otherwise fail
 * silently. Emails the merchant once per bill — to the same address that gets their
 * "you got paid" notices — so they can add the email in their PMS and click Resend.
 */
class MissingRecipientAlertService
{
    public const EVENT = 'PAYMENT_LINK_NO_RECIPIENT';

    public function alertOnce(Invoice $invoice, string $pmsLabel = 'Clio'): void
    {
        $alreadyAlerted = AuditLog::query()
            ->where('event_type', self::EVENT)
            ->where('entity_type', 'invoice')
            ->where('entity_id', (string) $invoice->id)
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        $client = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();

        // A firm that routes every link to its own admin address doesn't need the client's.
        if ($client?->payment_link_override_enabled && ($client->payment_link_recipient ?? 'customer') === 'admin') {
            return;
        }

        $emailConfig  = $client ? EmailConfiguration::where('client_id', $client->id)->first() : null;
        $merchantMail = trim((string) ($emailConfig?->reply_to_email ?: $client?->payment_link_admin_email ?: ''));
        $isTrust      = strtoupper((string) $invoice->fund_type) === 'TRUST';
        $what         = $isTrust ? 'Trust Request' : 'Bill';
        $number       = (string) ($invoice->invoice_number ?: $invoice->external_invoice_id);
        $customer     = (string) (data_get($invoice->raw_payload, 'invoice.data.client.name') ?: 'this client');

        AuditLogger::log(self::EVENT, 'invoice', (string) $invoice->id, [
            'pms_source'     => $invoice->pms_source,
            'pms_client_id'  => $invoice->pms_client_id,
            'invoice_number' => $number,
            'merchant_email' => $merchantMail !== '' ? $merchantMail : null,
        ]);

        if ($merchantMail === '') {
            Log::warning('Bill has no client email and the merchant has no notification email — no alert sent', [
                'invoice_id'    => $invoice->id,
                'pms_client_id' => $invoice->pms_client_id,
            ]);

            return;
        }

        $body = "{$what} #{$number} for {$customer} is ready to send, but {$customer} has no primary email address in {$pmsLabel}, "
            ."so SimpHub could not email the payment link.\n\n"
            ."To send it: add a primary email address to the contact in {$pmsLabel}, then open SimpHub's Invoice List and click Resend on {$what} #{$number}.";

        try {
            Mail::raw($body, function ($mail) use ($merchantMail, $what, $number) {
                $mail->to($merchantMail)->subject("Action needed: {$what} #{$number} has no client email");
            });
        } catch (Throwable $e) {
            Log::error('Missing recipient alert email failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
