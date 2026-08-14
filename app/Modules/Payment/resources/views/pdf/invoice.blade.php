<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: Georgia, 'Times New Roman', serif; color: #1f2937; font-size: 13px; }
    .logo { text-align: center; margin-bottom: 18px; }
    .logo img { max-height: 56px; max-width: 220px; }
    .title { font-size: 28px; letter-spacing: 0.1em; font-weight: bold; text-align: center; margin-bottom: 4px; color: #1f2937; }
    .subtitle { text-align: center; color: #6b7280; font-size: 11px; margin-bottom: 28px; letter-spacing: 0.05em; }
    table.parties { width: 100%; margin-bottom: 24px; }
    table.parties td { vertical-align: top; width: 50%; font-size: 12px; line-height: 1.6; }
    table.parties .heading { font-weight: bold; text-transform: uppercase; font-size: 10px; color: #6b7280; letter-spacing: 0.05em; padding-bottom: 4px; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.items th { background: #1f2937; color: #ffffff; text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
    table.items td { padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 12px; }
    table.items td.amt, table.items th.amt { text-align: right; }
    table.totals { width: 100%; margin-top: 4px; }
    table.totals td { padding: 5px 10px; font-size: 12px; }
    table.totals td.amt { text-align: right; }
    table.totals tr.grand td { border-top: 2px solid #1f2937; font-weight: bold; font-size: 15px; padding-top: 10px; }
    .pay-panel { margin-top: 26px; border: 1px solid #d1d5db; padding: 16px 20px; text-align: center; }
    .pay-panel .note { font-size: 11px; color: #6b7280; margin-bottom: 10px; }
    .pay-button { display: inline-block; background: #1f2937; color: #ffffff; padding: 10px 26px; text-decoration: none; font-size: 13px; font-weight: bold; }
    .pay-link { color: #6b7280; font-size: 10px; margin-top: 8px; }
    .footer { margin-top: 40px; text-align: center; color: #9ca3af; font-size: 9px; }
    .footer img { height: 11px; vertical-align: middle; }
</style>
</head>
<body>
    @if(!empty($logoUrl))
    <div class="logo"><img src="{{ $logoUrl }}" alt="{{ $merchantName }}"></div>
    @endif

    <div class="title">INVOICE</div>
    <div class="subtitle">
        #{{ $invoiceNumber }}
        @if(!empty($issueDate)) &nbsp;&bull;&nbsp; Issued {{ $issueDate }} @endif
        @if(!empty($dueDate)) &nbsp;&bull;&nbsp; Due {{ $dueDate }} @endif
    </div>

    <table class="parties">
        <tr><td class="heading">From</td><td class="heading">Bill To</td></tr>
        <tr><td>{{ $merchantName ?: 'Your Merchant' }}</td><td>{{ $customerName ?: 'Customer' }}</td></tr>
    </table>

    <table class="items">
        <tr><th>Description</th><th class="amt">Amount</th></tr>
        <tr><td>Invoice #{{ $invoiceNumber }}</td><td class="amt">{{ $currency }} {{ $amount }}</td></tr>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="amt">{{ $currency }} {{ $amount }}</td></tr>
        <tr class="grand"><td>Total Due</td><td class="amt">{{ $currency }} {{ $amount }}</td></tr>
    </table>

    <div class="pay-panel">
        <div class="note">This invoice can be paid securely online.</div>
        <a href="{{ $paymentUrl }}" class="pay-button">PAY THIS INVOICE</a>
        <div class="pay-link">{{ $paymentUrl }}</div>
    </div>

    <div class="footer">Powered by SimpHub</div>
</body>
</html>
