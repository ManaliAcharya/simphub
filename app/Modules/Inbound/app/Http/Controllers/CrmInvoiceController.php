<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Jobs\DispatchCustomWebhookJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\CustomInvoiceIngestionService;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class CrmInvoiceController extends Controller
{
    public function __construct(
        private readonly CustomInvoiceIngestionService $ingestion,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $validated = $request->validate([
            'amount_cents'         => ['required', 'integer', 'min:1'],
            'currency'             => ['required', 'string', 'size:3'],
            'invoice_number'       => ['required', 'string', 'max:64'],
            'description'          => ['nullable', 'string', 'max:500'],
            'customer'             => ['required', 'array'],
            'customer.name'        => ['required', 'string', 'max:255'],
            'customer.email'       => ['required', 'email', 'max:255'],
            'customer.phone'       => ['nullable', 'string', 'max:30'],
            'success_redirect_url' => ['required', 'url'],
            'cancel_redirect_url'  => ['required', 'url'],
            'metadata'             => ['nullable', 'array', 'max:20'],
        ]);

        try {
            $result = $this->ingestion->ingest(
                $validated['invoice_number'],
                array_merge($validated, [
                    'pms_client_id' => $client->pms_client_id,
                    'fund_type'     => 'OPERATING',
                ])
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'validation_error', 'message' => $e->getMessage()],
            ], 400);
        }

        $session = $result['payment_session'];

        return response()->json([
            'invoice_id'  => $result['invoice']->id,
            'status'      => 'pending',
            'payment_url' => $this->paymentLinks->urlForSession($session),
            'expires_at'  => $session->expires_at->toIso8601String(),
        ], 201);
    }

    public function show(Request $request, string $invoiceId): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $invoice = Invoice::query()
            ->where('id', $invoiceId)
            ->where('pms_client_id', $client->pms_client_id)
            ->where('pms_source', 'custom')
            ->first();

        if (! $invoice) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Invoice not found.'],
            ], 404);
        }

        $session = PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->latest()
            ->first();

        return response()->json([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status'         => strtolower($invoice->status),
            'amount_cents'   => $invoice->amount_cents,
            'currency'       => $invoice->currency,
            'description'    => $invoice->description,
            'customer'       => $invoice->customer,
            'metadata'       => $invoice->metadata,
            'payment_url'    => $session ? $this->paymentLinks->urlForSession($session) : null,
            'expires_at'     => $session?->expires_at?->toIso8601String(),
            'created_at'     => $invoice->created_at->toIso8601String(),
        ]);
    }

    public function cancel(Request $request, string $invoiceId): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $invoice = Invoice::query()
            ->where('id', $invoiceId)
            ->where('pms_client_id', $client->pms_client_id)
            ->where('pms_source', 'custom')
            ->first();

        if (! $invoice) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Invoice not found.'],
            ], 404);
        }

        if ($invoice->status === 'CANCELLED') {
            return response()->json([
                'error' => ['code' => 'validation_error', 'message' => 'Invoice is already cancelled.'],
            ], 400);
        }

        $hasCapturedTransaction = Transaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('transaction_type', 'debit')
            ->where('status', 'CAPTURED')
            ->exists();

        if ($hasCapturedTransaction) {
            return response()->json([
                'error' => [
                    'code'    => 'validation_error',
                    'message' => 'This invoice has a captured transaction. Use POST /v1/transactions/{transaction_id}/cancel to void it at the gateway.',
                ],
            ], 400);
        }

        $invoice->update(['status' => 'CANCELLED']);

        PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT', 'PROCESSING'])
            ->update(['status' => 'FAILED']);

        if (! empty($client->webhook_url)) {
            DispatchCustomWebhookJob::dispatch($invoice->id, 'invoice.cancelled');
        }

        return response()->json([
            'invoice_id' => $invoice->id,
            'status'     => 'cancelled',
        ]);
    }
}
