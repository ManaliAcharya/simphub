<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice Payment Link</title>
</head>
<body style="font-family: Arial, sans-serif; color: #14213d; line-height: 1.5;">
    <h2>Invoice ready for payment</h2>
    <p>Your invoice is ready. Use the secure payment link below to complete payment.</p>
    <p><strong>Invoice:</strong> {{ $invoice->invoice_number ? '#'.$invoice->invoice_number : $invoice->external_invoice_id }}</p>
    <p><strong>Amount:</strong> {{ number_format($invoice->amount_cents / 100, 2) }} {{ $invoice->currency }}</p>
    <p>
        <a href="{{ $paymentUrl }}" style="display: inline-block; padding: 12px 18px; background: #14213d; color: #fff; text-decoration: none; border-radius: 8px;">
            Pay invoice
        </a>
    </p>
    <p>If the button does not work, open this URL:</p>
    <p>{{ $paymentUrl }}</p>
</body>
</html>
