<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACH Payment Returned</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

<div style="max-width:600px;margin:32px auto;background:#ffffff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);overflow:hidden;">

    {{-- Logo --}}
    @if($logoUrl)
    <div style="padding:28px 32px 0;text-align:center;">
        <img src="{{ $logoUrl }}" alt="{{ $merchantName }}" style="max-height:60px;max-width:200px;object-fit:contain;display:inline-block;">
    </div>
    @endif

    {{-- Body --}}
    <div style="padding:28px 32px 32px;">

        {{-- Icon --}}
        <div style="margin-bottom:18px;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;background:#b91c1c;border-radius:50%;">
                <span style="color:#ffffff;font-size:22px;line-height:1;">!</span>
            </span>
        </div>

        <h1 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#111827;line-height:1.2;">
            ACH payment returned: {{ $documentLabel }} #{{ $invoiceRef }}
        </h1>
        <p style="margin:0 0 24px;font-size:14px;color:#374151;">
            The bank returned this ACH payment, so the funds were not received. It was already recorded as paid
            in {{ $pmsSource }}. <strong>Please reverse this payment in {{ $pmsSource }}</strong>{{ $isTrust ? " on the client's trust ledger" : '' }}.
            The {{ strtolower($documentLabel) }} is open again in SimpHub.
        </p>

        <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 20px;">

        <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#111827;">Returned payment</p>

        <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">{{ $documentLabel }} no.</td>
                <td style="padding:9px 0;text-align:right;font-weight:500;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $invoiceRef }}</td>
            </tr>
            @if($customerName)
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Customer</td>
                <td style="padding:9px 0;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $customerName }}</td>
            </tr>
            @endif
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Deposited to</td>
                <td style="padding:9px 0;text-align:right;font-weight:600;color:{{ $isTrust ? '#b91c1c' : '#111827' }};border-bottom:1px solid #f3f4f6;">{{ $accountLabel }}</td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Amount returned</td>
                <td style="padding:9px 0;text-align:right;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $currency }} {{ $totalAmount }}</td>
            </tr>
            @if($returnCode || $returnReason)
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Return reason</td>
                <td style="padding:9px 0;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ trim($returnCode.($returnCode && $returnReason ? ' – ' : '').$returnReason) }}</td>
            </tr>
            @endif
            @if($authorizationId)
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Authorization ID</td>
                <td style="padding:9px 0;text-align:right;color:#111827;font-family:'Courier New',Courier,monospace;font-size:13px;letter-spacing:0.5px;border-bottom:1px solid #f3f4f6;">{{ $authorizationId }}</td>
            </tr>
            @endif
            <tr>
                <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #f3f4f6;">Payment date</td>
                <td style="padding:9px 0;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $paidDate }}</td>
            </tr>
            <tr>
                <td style="padding:9px 0;color:#6b7280;">Returned on</td>
                <td style="padding:9px 0;text-align:right;color:#111827;">{{ $returnedDate }}</td>
            </tr>
        </table>

        <div style="margin-top:24px;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:13px;color:#991b1b;">
            Action needed: reverse this payment in {{ $pmsSource }} so the {{ strtolower($documentLabel) }}{{ $isTrust ? ' and the trust balance' : '' }}
            match the money actually received.
        </div>

        @if($lawcusMatterUrl)
        <div style="margin-top:14px;text-align:center;">
            <a href="{{ $lawcusMatterUrl }}"
               style="display:inline-block;padding:11px 22px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:13px;">
                Open matter in Lawcus
            </a>
        </div>
        @endif

    </div>

    {{-- Footer --}}
    <div style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 32px;font-size:11px;color:#6b7280;text-align:center;line-height:1.6;">
        Please don't reply to this email. If you need any help regarding this message, please contact your support representative.
        <div style="margin-top:8px;">
            Powered by <img src="{{ rtrim(config('app.url'), '/') }}/images/logo/simphub-favicon.jpeg" alt="SimpHub" width="16" height="16" style="height:16px;width:16px;border-radius:3px;vertical-align:middle;"> <strong>SimpHub</strong>
        </div>
    </div>

</div>

</body>
</html>
