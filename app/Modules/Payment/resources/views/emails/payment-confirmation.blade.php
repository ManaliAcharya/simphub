<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmed</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

<div style="max-width:600px;margin:32px auto;background:#ffffff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);overflow:hidden;">

    {{-- Logo --}}
    @if($logoUrl)
    <div style="padding:28px 32px 0;">
        <img src="{{ $logoUrl }}" alt="{{ $merchantName }}" style="max-height:60px;max-width:200px;object-fit:contain;display:block;">
    </div>
    @endif

    {{-- Body --}}
    <div style="padding:28px 32px 32px;">

        {{-- Amount heading --}}
        <h1 style="margin:0 0 6px;font-size:26px;font-weight:700;color:#111827;line-height:1.2;">
            You paid {{ $currency }} {{ $invoiceAmount }}
        </h1>
        <p style="margin:0 0 24px;font-size:14px;color:#6b7280;">
            @if($merchantName)to <strong style="color:#111827;">{{ $merchantName }}</strong> on @endif{{ $paidDate }}
        </p>

        <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 20px;">

        {{-- Payment details heading --}}
        <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#111827;">Payment details</p>

        {{-- Invoice breakdown --}}
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:0;">
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Invoice no.</td>
                <td style="padding:9px 0;text-align:right;color:#2563eb;font-weight:500;border-bottom:1px solid #f3f4f6;">{{ $invoiceRef }}</td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Invoice amount</td>
                <td style="padding:9px 0;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $currency }} {{ $invoiceAmount }}</td>
            </tr>
            @if($feeCents > 0)
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Processing fee</td>
                <td style="padding:9px 0;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $currency }} {{ $feeAmount }}</td>
            </tr>
            @endif
            <tr>
                <td style="padding:9px 0;font-weight:600;color:#111827;">Total amount</td>
                <td style="padding:9px 0;text-align:right;font-weight:700;color:#111827;">{{ $currency }} {{ $totalAmount }}</td>
            </tr>
        </table>

        <hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;">

        {{-- Payment status details --}}
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Status</td>
                <td style="padding:9px 0;text-align:right;border-bottom:1px solid #f3f4f6;">
                    <span style="color:#16a34a;font-weight:600;">Paid</span>
                </td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#6b7280;@if($authorizationId) border-bottom:1px solid #f3f4f6; @endif">Payment method</td>
                <td style="padding:9px 0;text-align:right;color:#111827;@if($authorizationId) border-bottom:1px solid #f3f4f6; @endif">{{ $paymentMethod }}</td>
            </tr>
            @if($authorizationId)
            <tr>
                <td style="padding:9px 0;color:#6b7280;">Authorization ID</td>
                <td style="padding:9px 0;text-align:right;color:#111827;font-family:'Courier New',Courier,monospace;font-size:13px;letter-spacing:0.5px;">{{ $authorizationId }}</td>
            </tr>
            @endif
        </table>

        {{-- Merchant contact --}}
        @if($merchantEmail || $merchantName)
        <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 18px;">
        <p style="margin:0 0 10px;font-size:12px;color:#9ca3af;">
            Please don't reply to this email. If you need any help regarding this message, please contact the business directly.
        </p>
        <p style="margin:0 0 12px;font-size:13px;color:#374151;">Thank you,</p>
        @if($merchantName)
        <p style="margin:0 0 2px;font-size:14px;font-weight:600;color:#111827;">{{ $merchantName }}</p>
        @endif
        @if($merchantEmail)
        <p style="margin:0;font-size:13px;color:#374151;">{{ $merchantEmail }}</p>
        @endif
        @endif

    </div>

    {{-- Footer --}}
    <div style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 32px;font-size:11px;color:#9ca3af;text-align:center;line-height:1.6;">
        This is an automated payment confirmation. No additional transfer fees or taxes apply.
    </div>

</div>

</body>
</html>
