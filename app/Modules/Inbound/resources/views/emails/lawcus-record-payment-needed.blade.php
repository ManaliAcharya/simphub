<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record payment in Lawcus</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

<div style="max-width:600px;margin:32px auto;background:#ffffff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);overflow:hidden;">

    @if($logoUrl)
    <div style="padding:28px 32px 0;text-align:center;">
        <img src="{{ $logoUrl }}" alt="{{ $merchantName }}" style="max-height:60px;max-width:200px;object-fit:contain;display:inline-block;">
    </div>
    @endif

    <div style="padding:28px 32px 32px;">

        <h1 style="margin:0 0 14px;font-size:22px;font-weight:700;color:#111827;line-height:1.2;">
            Action needed: record payment for Invoice #{{ $invoiceRef }}
        </h1>
        <p style="margin:0 0 20px;font-size:14px;color:#374151;line-height:1.5;">
            We received a payment but couldn't record it in Lawcus automatically. Please record it manually:
        </p>

        @if($actionLink)
        <div style="margin:0 0 20px;text-align:center;">
            <a href="{{ $actionLink['url'] }}"
               style="display:inline-block;padding:11px 22px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;">
                {{ $actionLink['label'] }}
            </a>
        </div>
        @endif

        <ol style="margin:0 0 20px;padding-left:20px;font-size:14px;color:#111827;line-height:1.7;">
            <li>
                @if($actionLink)Click the button above to open @else In Lawcus, open @endif
                Invoice #{{ $invoiceRef }}@if($customerName || $matterName) ({{ implode(', ', array_filter([$customerName, $matterName])) }})@endif.
            </li>
            <li>Click <strong>Add Payment</strong>.</li>
            <li>Payment source: <strong>Direct client payment</strong>.</li>
            <li>Destination account: <strong style="color:{{ $isTrust ? '#b91c1c' : '#111827' }};">{{ $destination }}</strong>.</li>
            <li>Amount: <strong>${{ $amountDisplay }}</strong>. Date: <strong>{{ $paymentDate }}</strong>.</li>
            <li>Reference/note: <strong>SimpHub transaction {{ $transactionRef }}, {{ $paymentMethod }}</strong>.</li>
            <li>Save, and confirm the invoice shows the updated balance.</li>
        </ol>

    </div>

    <div style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 32px;font-size:11px;color:#6b7280;text-align:center;line-height:1.6;">
        Please don't reply to this email. If you need any help regarding this message, please contact your support representative.
        <div style="margin-top:8px;">
            Powered by <img src="{{ rtrim(config('app.url'), '/') }}/images/logo/simphub-favicon.jpeg" alt="SimpHub" width="16" height="16" style="height:16px;width:16px;border-radius:3px;vertical-align:middle;"> <strong>SimpHub</strong>
        </div>
    </div>

</div>

</body>
</html>
