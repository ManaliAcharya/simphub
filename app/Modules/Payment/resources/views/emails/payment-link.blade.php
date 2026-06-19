<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice Payment Link</title>
</head>
<body style="font-family: Arial, sans-serif; color: #14213d; line-height: 1.5;">
    @if(!empty($logoUrl))
    <div style="margin-bottom: 24px;">
        <img src="{{ $logoUrl }}" alt="Company Logo"
             style="max-height: 60px; max-width: 200px; object-fit: contain; display: block;">
    </div>
    @endif
    <h2>Invoice ready for payment</h2>
    <p>A payment of <strong>{{ $invoice->currency }} {{ number_format($invoice->amount_cents / 100, 2) }}</strong> is due for Invoice <strong>#{{ $invoice->invoice_number ?? $invoice->external_invoice_id }}</strong>.</p>
    <p>
        <a href="{{ $paymentUrl }}" style="display: inline-block; padding: 12px 18px; background: #14213d; color: #fff; text-decoration: none; border-radius: 8px;">
            Pay now
        </a>
    </p>
    <p style="color: #6b7280; font-size: 12px;">If the button does not work, open this URL:<br>{{ $paymentUrl }}</p>
    @if(!empty($merchantName))
    <p style="margin-top: 24px; color: #6b7280; font-size: 13px;">Thank you,<br>{{ $merchantName }}</p>
    @endif
</body>
</html>
