<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice Payment Reminder Sent</title>
</head>
<body style="font-family: Arial, sans-serif; color: #14213d; line-height: 1.6;">
    @if(!empty($logoUrl))
    <div style="margin-bottom: 24px;">
        <img src="{{ $logoUrl }}" alt="Company Logo"
             style="max-height: 60px; max-width: 200px; object-fit: contain; display: block;">
    </div>
    @endif

    <p>A payment reminder was sent for the following invoice, which is still awaiting payment:</p>

    <table style="border-collapse: collapse; margin-bottom: 20px;">
        @if($customerName)
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: 600; white-space: nowrap;">Customer</td>
            <td style="padding: 4px 0;">{{ $customerName }}</td>
        </tr>
        @endif
        @if($customerEmail)
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: 600; white-space: nowrap;">Customer Email</td>
            <td style="padding: 4px 0;">{{ $customerEmail }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: 600; white-space: nowrap;">Invoice</td>
            <td style="padding: 4px 0;">#{{ $invoiceRef }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; font-weight: 600; white-space: nowrap;">Amount</td>
            <td style="padding: 4px 0;">{{ $currency }} {{ $amount }}</td>
        </tr>
    </table>

    <p><strong>Payment Link:</strong></p>
    <p>
        <a href="{{ $paymentUrl }}" style="display: inline-block; padding: 12px 18px; background: #14213d; color: #fff; text-decoration: none; border-radius: 8px;">
            Open payment link
        </a>
    </p>
    <p style="color: #6b7280; font-size: 12px;">{{ $paymentUrl }}</p>

    @if($merchantName)
    <p style="margin-top: 24px; color: #6b7280; font-size: 13px;">{{ $merchantName }}</p>
    @endif
</body>
</html>
