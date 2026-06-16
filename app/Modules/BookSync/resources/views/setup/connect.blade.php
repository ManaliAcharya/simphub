<x-booksync::layouts.master title="Connect QuickBooks">

<p class="eyebrow">BookSync Setup</p>

@if(isset($oauthError))
    <h1>Authorization Failed</h1>
    <div class="alert alert-error">{{ $oauthError }}</div>
    @if($setupToken)
    <div class="actions">
        <a href="{{ route('booksync.setup.show', $setupToken) }}" class="button btn-secondary">Try Again</a>
    </div>
    @endif
@elseif($merchant)
    <h1>Connect QuickBooks</h1>
    <p class="copy">Hi {{ $merchant->name }}, your payment processor needs to connect to your QuickBooks Online account to automatically record your sales transactions.</p>

    <div class="panel" style="max-width:480px;">
        <h2 style="margin-bottom:8px;">What will happen</h2>
        <ul style="color:#5f7089;padding-left:20px;line-height:1.8;margin:0 0 20px;">
            <li>You'll be redirected to Intuit to sign in</li>
            <li>Grant read/write access to your QuickBooks company</li>
            <li>Select which bank account transactions post to</li>
            <li>Done — your payment processor handles the rest automatically</li>
        </ul>

        <div style="background:rgba(19,34,56,.04);border-radius:16px;padding:14px 16px;font-size:.88rem;color:#6b7c93;margin-bottom:20px;">
            Only sales receipt data is written to QuickBooks. No payment processing occurs through this connection.
        </div>

        <a href="{{ route('booksync.setup.redirect', $setupToken) }}" class="button btn-primary" style="width:100%;justify-content:center;box-sizing:border-box;">
            Connect QuickBooks Online
        </a>
    </div>
@else
    <h1>Invalid Setup Link</h1>
    <p class="copy">This setup link is invalid or has already been used. Please contact your payment provider for a new link.</p>
@endif

</x-booksync::layouts.master>
