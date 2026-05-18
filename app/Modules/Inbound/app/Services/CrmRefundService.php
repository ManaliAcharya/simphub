<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Jobs\DispatchCustomWebhookJob;
use Modules\Inbound\Models\Client;
use Modules\Outbound\DTOs\RefundRequest;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Routing\Models\RoutingRule;
use RuntimeException;

class CrmRefundService
{
    public function __construct(
        private readonly GatewayAdapterFactory $gateways,
    ) {}

    /**
     * Process a refund for a captured transaction.
     *
     * @throws RuntimeException on validation failure or gateway decline
     */
    public function refund(Client $client, string $transactionId, ?int $amountCents, ?string $reason): Transaction
    {
        $original = Transaction::query()
            ->where('id', $transactionId)
            ->where('transaction_type', 'debit')
            ->whereHas('invoice', fn ($q) => $q
                ->where('pms_client_id', $client->pms_client_id)
                ->where('pms_source', 'custom')
            )
            ->first();

        if (! $original) {
            throw new RuntimeException('not_found');
        }

        if ($original->status !== 'CAPTURED') {
            throw new RuntimeException('Transaction is not in a refundable state (must be CAPTURED).');
        }

        $alreadyRefunded = Transaction::query()
            ->where('parent_transaction_id', $original->id)
            ->where('transaction_type', 'credit')
            ->whereIn('status', ['REFUNDED'])
            ->sum('amount_cents');

        $refundable = $original->amount_cents - $alreadyRefunded;

        if ($refundable <= 0) {
            throw new RuntimeException('This transaction has already been fully refunded.');
        }

        $refundAmount = $amountCents ?? $original->amount_cents;

        if ($refundAmount > $refundable) {
            throw new RuntimeException("Refund amount ({$refundAmount}) exceeds refundable balance ({$refundable}).");
        }

        $routingRule = RoutingRule::find($original->routing_rule_id);
        $midCredentials = [];
        if ($routingRule?->mid_credentials) {
            try {
                $midCredentials = (array) decrypt($routingRule->mid_credentials);
            } catch (\Throwable) {}
        }

        $response = $this->gateways->make($original->gateway)->refund(new RefundRequest(
            gatewayTxnId: (string) $original->gateway_txn_id,
            gatewayToken: (string) $original->gateway_token,
            amountInCents: $refundAmount,
            currency: (string) $original->currency,
            midCredentials: $midCredentials,
            metadata: [
                'original_transaction_id' => $original->id,
                'invoice_id'              => $original->invoice_id,
                'reason'                  => $reason ?? '',
            ],
        ));

        if (! $response->approved) {
            throw new RuntimeException($response->message ?? 'Refund was declined by the gateway.');
        }

        return DB::transaction(function () use ($original, $refundAmount, $refundable, $response, $reason) {
            $refundTxn = Transaction::query()->create([
                'payment_session_id'    => $original->payment_session_id,
                'invoice_id'            => $original->invoice_id,
                'routing_rule_id'       => $original->routing_rule_id,
                'gateway'               => $original->gateway,
                'mid'                   => $original->mid,
                'gateway_txn_id'        => $response->transactionReference,
                'gateway_token'         => (string) $response->gatewayToken,
                'status'                => 'REFUNDED',
                'fund_type'             => $original->fund_type,
                'amount_cents'          => $refundAmount,
                'currency'              => $original->currency,
                'transaction_type'      => 'credit',
                'parent_transaction_id' => $original->id,
                'gateway_response'      => $response->raw,
            ]);

            // If fully refunded, update invoice status
            if ($refundAmount >= $refundable && $refundable === $original->amount_cents) {
                Invoice::where('id', $original->invoice_id)
                    ->update(['status' => 'REFUNDED']);
            }

            AuditLogger::log('REFUND_PROCESSED', 'transaction', $refundTxn->id, [
                'original_transaction_id' => $original->id,
                'amount_cents'            => $refundAmount,
                'gateway'                 => $original->gateway,
                'reason'                  => $reason ?? '',
            ]);

            $invoice = $refundTxn->invoice;
            if ($invoice && ! empty($invoice->webhook_url)) {
                DispatchCustomWebhookJob::dispatch($invoice->id, 'refund.completed');
            }

            return $refundTxn;
        });
    }
}
