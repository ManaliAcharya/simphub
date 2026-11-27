<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\Invoice;
use Modules\Payment\Services\InvoiceActivityLog;

/**
 * The "History" button on the invoice list: what has happened to one invoice so far.
 * The route sits behind merchant.auth, which already limits {pms_client_id} to the
 * signed-in client; the invoice must belong to that client too.
 */
class InvoiceHistoryController extends Controller
{
    public function show(string $pms_client_id, Invoice $invoice, InvoiceActivityLog $log): JsonResponse
    {
        abort_unless((string) $invoice->pms_client_id === $pms_client_id, 404);

        return response()->json([
            'invoice' => $invoice->invoice_number ? '#'.$invoice->invoice_number : 'this invoice',
            'entries' => $log->forInvoice($invoice),
        ]);
    }
}
