<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Arr;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;

class EmailConfigurationService
{
    public function resolveForClient(?Client $client): ?EmailConfiguration
    {
        if (! $client) {
            return null;
        }

        return EmailConfiguration::where('client_id', $client->id)->first();
    }

    public function replaceVariables(string $template, array $data): string
    {
        return EmailAmount::fill($template, $data);
    }

    public function buildTemplateData(Invoice $invoice, string $paymentUrl, Client $client, ?EmailConfiguration $config): array
    {
        return [
            'merchant_name'  => $client->client_name ?? '',
            'customer_name'  => $this->extractCustomerName($invoice),
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->external_invoice_id ?? ''),
            'amount'         => EmailAmount::format((int) $invoice->amount_cents, $invoice->currency),
            'due_date'       => $this->extractDueDate($invoice),
            'payment_link'   => $paymentUrl,
            'merchant_phone' => '',
            'merchant_email' => $config?->reply_to_email ?? '',
        ];
    }

    private function extractCustomerName(Invoice $invoice): string
    {
        $raw = $invoice->raw_payload ?? [];

        return (string) (
            Arr::get($raw, 'customer.Customer.DisplayName')
            ?? Arr::get($raw, 'customer.data.display_number')
            ?? ''
        );
    }

    private function extractDueDate(Invoice $invoice): string
    {
        $raw = $invoice->raw_payload ?? [];

        return (string) (
            Arr::get($raw, 'invoice.Invoice.DueDate')
            ?? Arr::get($raw, 'invoice.data.due_at')
            ?? ''
        );
    }
}
