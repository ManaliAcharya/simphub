<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Inbound\Models\MindbodySaleLink;
use Modules\Inbound\Models\MindbodySite;
use Modules\Payment\Mail\PaymentLinkMail;
use RuntimeException;

class MindbodySaleIngestionService
{
    public function __construct(
        private readonly MindbodyApiClient $api,
    ) {}

    public function processSale(MindbodySite $site, string $saleId, array $rawPayload): MindbodySaleLink
    {
        // 1. Fetch sale details from Mindbody
        $sale = $this->api->fetchSale($site, $saleId);

        $mbClientId = (string) ($sale['ClientId'] ?? '');

        if ($mbClientId === '') {
            throw new RuntimeException("Sale {$saleId} has no ClientId.");
        }

        // 2. Fetch client demographics (email, phone, name)
        $clientData = $this->api->fetchClient($site, $mbClientId);
        $clientEmail = (string) ($clientData['Email']       ?? '');
        $clientPhone = (string) ($clientData['MobilePhone'] ?? $clientData['HomePhone'] ?? '');
        $clientName  = trim(($clientData['FirstName'] ?? '') . ' ' . ($clientData['LastName'] ?? ''));

        // 3. Determine amount based on payment_link_mode
        $amountDollars = $this->resolveAmount($site, $sale, $mbClientId);

        if ($amountDollars <= 0) {
            Log::info('Mindbody sale: zero or negative amount, skipping', [
                'sale_id'   => $saleId,
                'site_id'   => $site->site_id,
                'amount'    => $amountDollars,
            ]);
            return $this->createSaleLink($site, $saleId, $mbClientId, $clientName, $clientEmail, $clientPhone, 0, $rawPayload, 'cancelled');
        }

        $amountCents = (int) round($amountDollars * 100);

        // 4. Create MindbodySaleLink record
        $link = $this->createSaleLink(
            $site, $saleId, $mbClientId, $clientName, $clientEmail, $clientPhone,
            $amountCents, $rawPayload, 'pending'
        );

        // 5. Generate payment URL
        $paymentUrl = $this->generatePaymentUrl($link);

        $link->forceFill([
            'payment_link_url' => $paymentUrl,
        ])->save();

        // 6. Send email (fall back gracefully if no email)
        if ($clientEmail !== '') {
            $this->sendPaymentLink($link, $clientEmail, $clientName);
        } elseif ($clientPhone !== '') {
            // SMS fallback — placeholder for SMS integration
            Log::warning('Mindbody: client has no email, SMS not yet implemented', [
                'sale_id' => $saleId,
                'phone'   => $clientPhone,
            ]);
        } else {
            Log::warning('Mindbody: client has no email or phone — payment link not sent', [
                'sale_id'   => $saleId,
                'client_id' => $mbClientId,
            ]);
        }

        Log::info('Mindbody sale ingested', [
            'sale_id'       => $saleId,
            'site_id'       => $site->site_id,
            'client_id'     => $mbClientId,
            'amount_cents'  => $amountCents,
            'email_sent'    => $clientEmail !== '',
        ]);

        return $link;
    }

    private function resolveAmount(MindbodySite $site, array $sale, string $mbClientId): float
    {
        if ($site->payment_link_mode === 'account_balance') {
            return $this->api->fetchAccountBalance($site, $mbClientId);
        }

        // per_sale: calculate unpaid balance on this sale
        $total = (float) ($sale['Total'] ?? 0.0);

        // Subtract already paid amounts
        $paid = 0.0;
        foreach ((array) ($sale['Payments'] ?? []) as $payment) {
            $paid += (float) ($payment['Amount'] ?? 0.0);
        }

        return max(0.0, $total - $paid);
    }

    private function createSaleLink(
        MindbodySite $site,
        string $saleId,
        string $mbClientId,
        string $clientName,
        string $clientEmail,
        string $clientPhone,
        int $amountCents,
        array $rawPayload,
        string $status,
    ): MindbodySaleLink {
        return MindbodySaleLink::query()->create([
            'mindbody_site_id'  => $site->id,
            'sale_id'           => $saleId,
            'client_id_mb'      => $mbClientId,
            'client_name'       => $clientName ?: null,
            'client_email'      => $clientEmail ?: null,
            'client_phone'      => $clientPhone ?: null,
            'sale_amount_cents' => $amountCents,
            'payment_status'    => $status,
            'raw_sale_payload'  => $rawPayload,
        ]);
    }

    private function generatePaymentUrl(MindbodySaleLink $link): string
    {
        // Payment page token = link UUID for now.
        // When Invoice+PaymentSession flow is wired up this will be the session token.
        return rtrim(config('app.url'), '/') . '/pay/mindbody/' . $link->id;
    }

    private function sendPaymentLink(MindbodySaleLink $link, string $email, string $name): void
    {
        try {
            Mail::to($email)->send(new PaymentLinkMail(
                paymentUrl: (string) $link->payment_link_url,
                amount: $link->sale_amount_cents / 100,
                clientName: $name,
            ));

            $link->forceFill([
                'payment_status'       => 'sent',
                'payment_link_sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Mindbody: failed to send payment link email', [
                'sale_id' => $link->sale_id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
