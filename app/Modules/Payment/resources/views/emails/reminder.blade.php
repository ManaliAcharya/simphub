<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice Payment Reminder</title>
</head>
<body style="font-family: Arial, sans-serif; color: #14213d; line-height: 1.5; margin: 0; padding: 0; background: #f5f5f5;">
    <div style="max-width: 600px; margin: 32px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.07);">

        <div style="padding: 32px 32px 24px;">

            @if(!empty($logoUrl))
            <div style="margin-bottom: 24px; text-align: center;">
                <img src="{{ $logoUrl }}" alt="Company Logo"
                     style="max-height: 60px; max-width: 200px; object-fit: contain; display: inline-block;">
            </div>
            @endif

            <h2 style="color: #14213d; margin: 0 0 16px; font-size: 20px;">Payment reminder</h2>

            <p style="margin: 0 0 16px;">
                Hi {{ $customerName ?: 'there' }},
            </p>

            <p style="margin: 0 0 16px;">
                This is a friendly reminder that Invoice #{{ $invoice->invoice_number ?? $invoice->external_invoice_id }}
                for <strong>{{ $invoice->currency }} {{ number_format($invoice->amount_cents / 100, 2) }}</strong>
                is still awaiting payment.
            </p>

            @if(!empty($dueDate))
            <p style="margin: 0 0 16px;">Due date: {{ $dueDate }}</p>
            @endif

            <p style="margin: 0 0 16px;">
                <a href="{{ $paymentUrl }}"
                   style="display: inline-block; padding: 14px 28px; background: {{ $primaryColor ?? '#2196F3' }}; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 15px;">
                    Pay Now
                </a>
            </p>

            <p style="color: #6b7280; font-size: 12px; margin: 0 0 16px;">If the button does not work, copy this link into your browser:<br>{{ $paymentUrl }}</p>

            @if(!empty($merchantName))
            <p style="margin-top: 24px;">Thank you,<br><strong>{{ $merchantName }}</strong></p>
            @endif

        </div>

        <div style="background: #f9fafb; padding: 14px 32px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb;">
            This email was sent by {{ $merchantName ?? 'your merchant' }}. Please do not reply to this email directly.
            <div style="margin-top: 8px;">
                Powered by <img src="{{ asset('images/logo/simphub-favicon.jpeg') }}" alt="SimpHub" width="16" height="16" style="height: 16px; width: 16px; border-radius: 3px; vertical-align: middle;"> <strong>SimpHub</strong>
            </div>
        </div>

    </div>
</body>
</html>
