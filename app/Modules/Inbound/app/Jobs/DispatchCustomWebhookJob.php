<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use RuntimeException;

class DispatchCustomWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 7;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $event,
    ) {}

    /**
     * Retry delays in seconds: 30s, 2m, 10m, 1h, 6h, 24h.
     * First attempt is immediate (dispatched with no delay).
     */
    public function backoff(): array
    {
        return [30, 120, 600, 3600, 21600, 86400];
    }

    public function handle(): void
    {
        $invoice = Invoice::find($this->invoiceId);

        if (! $invoice || empty($invoice->webhook_url)) {
            return;
        }

        $client = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
        $secret = $client?->webhook_secret;

        $body      = $this->buildPayload($invoice);
        $json      = (string) json_encode($body);
        $timestamp = time();
        $signature = $this->sign($secret, $timestamp, $json);

        $response = Http::withHeaders([
            'Content-Type'        => 'application/json',
            'Middleware-Signature' => "t={$timestamp},v1={$signature}",
        ])->post($invoice->webhook_url, $body);

        if (! $response->successful()) {
            Log::warning('Custom webhook delivery failed', [
                'invoice_id' => $this->invoiceId,
                'event'      => $this->event,
                'status'     => $response->status(),
                'attempt'    => $this->attempts(),
            ]);

            throw new RuntimeException("Webhook delivery failed with HTTP {$response->status()}.");
        }
    }

    private function buildPayload(Invoice $invoice): array
    {
        return [
            'id'         => 'evt_' . Str::uuid(),
            'event'      => $this->event,
            'created_at' => now()->toIso8601String(),
            'data'       => $this->buildData($invoice),
        ];
    }

    private function buildData(Invoice $invoice): array
    {
        $data = [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status'         => strtolower((string) $invoice->status),
            'amount_cents'   => $invoice->amount_cents,
            'currency'       => $invoice->currency,
            'customer'       => $invoice->customer,
            'metadata'       => $invoice->metadata ?? [],
        ];

        if ($this->event === 'invoice.paid') {
            $transaction = Transaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'CAPTURED')
                ->latest()
                ->first();

            if ($transaction) {
                $data['transaction_id'] = $transaction->id;
                $data['gateway']        = $transaction->gateway;
                $data['gateway_txn_id'] = $transaction->gateway_txn_id;
                $data['fund_type']      = $transaction->fund_type;
            }
        }

        return $data;
    }

    private function sign(?string $secret, int $timestamp, string $json): string
    {
        if (! $secret) {
            return '';
        }

        return hash_hmac('sha256', "{$timestamp}.{$json}", $secret);
    }
}
