<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Models\IdempotencyKey;

class PaymentSessionService
{
    public function createForInvoice(Invoice $invoice, array $attributes = []): PaymentSession
    {
        return new PaymentSession([
            'invoice_id' => $invoice->getKey(),
            ...$attributes,
        ]);
    }

    public function create(Invoice $invoice): PaymentSession
    {
        return DB::transaction(function () use ($invoice) {
            $session = PaymentSession::create([
                'invoice_id' => $invoice->id,
                'status' => 'PENDING',
                'fund_type' => (string) $invoice->fund_type,
                'hosted_url_token' => (string) Str::uuid(),
                'expires_at' => now()->addHours(72),
                'idempotency_key' => $this->buildKey($invoice),
            ]);

            IdempotencyKey::create([
                'key' => $session->idempotency_key,
                'action' => 'PAYMENT_CHARGE',
                'response_status' => 'PENDING',
                'payment_session_id' => $session->id,
                'expires_at' => now()->addDays(7),
            ]);

            AuditLogger::log('SESSION_CREATED', 'payment_session', $session->id);

            return $session;
        });
    }

    private function buildKey(Invoice $invoice): string
    {
        return hash('sha256', implode('|', [
            $invoice->id,
            (string) $invoice->amount_cents,
            (string) $invoice->fund_type,
        ]));
    }
}
