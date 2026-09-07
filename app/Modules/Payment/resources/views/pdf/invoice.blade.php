<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@php
    $accentColor = $primaryColor ?: '#16243d';
@endphp
<style>
    body { font-family: Georgia, 'Times New Roman', serif; color: #1c2530; font-size: 13px; background: #ffffff; margin: 0; }
    .sheet { background: #ffffff; padding: 8px 12px; }

    .title { text-align: center; letter-spacing: 0.28em; font-size: 26px; color: #16243d; margin: 0; font-weight: bold; }
    .title-rule { width: 60px; height: 3px; background: #2f8f7d; margin: 14px auto 10px; }
    .doc-meta { text-align: center; margin-bottom: 26px; }
    .doc-meta div { font-size: 14px; color: #16243d; line-height: 1.7; }

    table.parties { width: 100%; margin-bottom: 24px; }
    table.parties td { vertical-align: top; font-size: 12px; line-height: 1.55; }
    table.parties .label { font-family: Arial, sans-serif; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: #5b6675; font-weight: bold; padding-bottom: 6px; }
    table.parties .name { font-size: 14px; font-weight: bold; margin-bottom: 3px; }
    table.parties .detail { color: #5b6675; }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.items th { background: {{ $accentColor }}; color: #ffffff; text-align: left; font-family: Arial, sans-serif; font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; padding: 9px 10px; font-weight: bold; }
    table.items th.num { text-align: right; }
    table.items td { padding: 9px 10px; font-size: 12.5px; border-bottom: 1px solid #e2e6ec; }
    table.items td.num { text-align: right; white-space: nowrap; }
    table.items .sub { display: block; font-size: 11px; color: #5b6675; margin-top: 2px; }

    table.totals { width: 100%; margin-top: 4px; }
    table.totals td { padding: 6px 10px; font-size: 12.5px; }
    table.totals td.lbl { text-align: right; color: #5b6675; }
    table.totals td.val { text-align: right; white-space: nowrap; width: 130px; }
    table.totals tr.grand td { border-top: 2px solid #16243d; font-size: 15px; font-weight: bold; color: #16243d; padding-top: 10px; }

    .payblock { margin-top: 24px; border: 1px solid #e2e6ec; border-top: 3px solid #2f8f7d; text-align: center; padding: 20px 16px; background: #fcfdfe; }
    .payblock p { margin: 0 0 14px; color: #5b6675; font-size: 12px; }
    .pay-button { display: inline-block; background: {{ $accentColor }}; color: #ffffff; text-decoration: none; font-family: Arial, sans-serif; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; font-weight: bold; padding: 12px 26px; }
    .pay-link { display: block; margin-top: 12px; font-size: 10px; color: #5b6675; }

    .notes { margin-top: 22px; font-size: 11.5px; color: #5b6675; line-height: 1.6; }
    .notes .label { font-family: Arial, sans-serif; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; }

    .footer { text-align: center; margin-top: 26px; padding-top: 14px; font-family: Arial, sans-serif; font-size: 11px; color: #9ca3af; }
    .footer img { height: 12px; vertical-align: middle; }
    .footer strong { color: #16243d; }
</style>
</head>
<body>
<div class="sheet">

    @if(!empty($logoUrl))
    <div style="text-align:center;margin-bottom:18px;"><img src="{{ $logoUrl }}" alt="{{ $merchantName }}" style="max-height:56px;max-width:220px;"></div>
    @endif

    <div class="title">INVOICE</div>
    <div class="title-rule"></div>
    <div class="doc-meta">
        <div><strong>Invoice #{{ $invoiceNumber }}</strong></div>
        @if(!empty($terms))<div>Terms: {{ $terms }}</div>@endif
        @if(!empty($issueDate))<div>Invoice Date: {{ $issueDate }}</div>@endif
        @if(!empty($dueDate))<div>Due Date: {{ $dueDate }}</div>@endif
    </div>

    <table class="parties">
        <tr>
            <td class="label">From</td>
            <td class="label">Bill To</td>
            @if(!empty($shippingAddress))
            <td class="label">Ship To</td>
            @endif
        </tr>
        <tr>
            <td>
                <div class="name">{{ $merchantName ?: 'Your Merchant' }}</div>
                @if(!empty($merchantAddress))
                <div class="detail">
                    @foreach($merchantAddress as $line){{ $line }}@if(!$loop->last)<br>@endif @endforeach
                </div>
                @endif
                @if(!empty($merchantContact))
                <div class="detail">{{ $merchantContact }}</div>
                @endif
            </td>
            <td>
                <div class="name">{{ $customerName ?: 'Customer' }}</div>
                @if(!empty($customerAddress))
                <div class="detail">
                    @foreach($customerAddress as $line){{ $line }}@if(!$loop->last)<br>@endif @endforeach
                </div>
                @endif
                @if(!empty($customerEmail))
                <div class="detail">{{ $customerEmail }}</div>
                @endif
            </td>
            @if(!empty($shippingAddress))
            <td>
                <div class="detail">
                    @foreach($shippingAddress as $line){{ $line }}@if(!$loop->last)<br>@endif @endforeach
                </div>
            </td>
            @endif
        </tr>
    </table>

    <table class="items">
        <tr>
            <th>Product or Service</th>
            <th>Description</th>
            <th class="num">Qty</th>
            <th class="num">Rate</th>
            <th class="num">Amount</th>
        </tr>
        @foreach ($lineItems as $item)
        <tr>
            <td>{{ $item['productOrService'] ?? '' }}</td>
            <td>
                {{ $item['description'] }}
                @if(!empty($item['subDescription']))
                <span class="sub">{{ $item['subDescription'] }}</span>
                @endif
            </td>
            <td class="num">{{ rtrim(rtrim(number_format($item['qty'], 2), '0'), '.') }}</td>
            <td class="num">{{ $currency }} {{ number_format($item['rate'], 2) }}</td>
            <td class="num">{{ $currency }} {{ number_format($item['amount'], 2) }}</td>
        </tr>
        @endforeach
    </table>

    <table class="totals">
        <tr>
            <td class="lbl">Subtotal</td>
            <td class="val">{{ $currency }} {{ $subtotal }}</td>
        </tr>
        @if(!empty($taxAmount))
        <tr>
            <td class="lbl">{{ $taxLabel }}</td>
            <td class="val">{{ $currency }} {{ $taxAmount }}</td>
        </tr>
        @endif
        <tr class="grand">
            <td class="lbl">Total Due</td>
            <td class="val">{{ $currency }} {{ $totalAmount }}</td>
        </tr>
    </table>

    <div class="payblock">
        <p>This invoice can be paid securely online.</p>
        <a href="{{ $paymentUrl }}" class="pay-button">Pay This Invoice</a>
        <span class="pay-link">{{ $paymentUrl }}</span>
    </div>

    @if(!empty($customerMemo))
    <div class="notes">
        <div class="label">Note to Customer</div>
        {{ $customerMemo }}
    </div>
    @endif

    @if(!empty($merchantContact))
    <div class="notes">
        <div class="label">Notes</div>
        Payment is due by the date shown above. Questions about this invoice? Contact {{ $merchantContact }}.
    </div>
    @endif

    <div class="footer">
        Powered by
        <img src="{{ $faviconUri }}" alt="">
        <strong>SimpHub</strong>
    </div>

</div>
</body>
</html>
