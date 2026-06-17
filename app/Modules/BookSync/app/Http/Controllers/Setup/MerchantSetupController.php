<?php

namespace Modules\BookSync\Http\Controllers\Setup;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Services\BookSyncQBClient;
use Modules\BookSync\Services\BookSyncQBOAuthService;

class MerchantSetupController extends Controller
{
    public function __construct(
        private readonly BookSyncQBOAuthService $oauth,
        private readonly BookSyncQBClient $qb,
    ) {}

    /** Step 1: Show "Connect QuickBooks" page for this merchant's setup token. */
    public function show(string $setupToken)
    {
        $merchant = BookSyncMerchant::where('setup_token', $setupToken)->firstOrFail();

        if ($merchant->status === 'active') {
            return view('booksync::setup.complete', compact('merchant'));
        }

        return view('booksync::setup.connect', compact('merchant', 'setupToken'));
    }

    /** Step 2: Redirect merchant to Intuit OAuth. */
    public function redirect(string $setupToken): RedirectResponse
    {
        BookSyncMerchant::where('setup_token', $setupToken)->firstOrFail();

        return redirect($this->oauth->authorizationUrl($setupToken));
    }

    /** Step 3: Intuit callback — exchange code, then show account selector. */
    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return view('booksync::setup.connect', [
                'merchant'   => null,
                'setupToken' => null,
                'oauthError' => $request->query('error_description', 'QuickBooks authorization was declined.'),
            ]);
        }

        $setupToken = $this->oauth->validateState($request->query('state'));
        $merchant   = $this->oauth->exchangeCode(
            code:       $request->query('code'),
            realmId:    $request->query('realmId', ''),
            setupToken: $setupToken,
        );

        $accounts  = $this->qb->fetchDepositAccounts($merchant);
        $items     = $this->qb->fetchItems($merchant);
        $customers = $this->qb->fetchCustomers($merchant);

        return view('booksync::setup.select-account', compact('merchant', 'accounts', 'items', 'customers', 'setupToken'));
    }

    /** Step 4: Save account selections. */
    public function saveAccount(Request $request, string $setupToken): RedirectResponse
    {
        $merchant = BookSyncMerchant::where('setup_token', $setupToken)->firstOrFail();

        $data = $request->validate([
            'deposit_account_id'    => ['required', 'string'],
            'deposit_account_name'  => ['required', 'string'],
            'default_item_id'       => ['required', 'string'],
            'default_item_name'     => ['required', 'string'],
            'default_customer_id'   => ['required', 'string'],
            'default_customer_name' => ['required', 'string'],
        ]);

        $postingToken = $merchant->posting_token ?? 'tok_' . Str::random(32);

        $merchant->forceFill([
            'deposit_account_id'    => $data['deposit_account_id'],
            'deposit_account_name'  => $data['deposit_account_name'],
            'default_item_id'       => $data['default_item_id'],
            'default_item_name'     => $data['default_item_name'],
            'default_customer_id'   => $data['default_customer_id'],
            'default_customer_name' => $data['default_customer_name'],
            'posting_token'         => $postingToken,
            'status'                => 'active',
        ])->save();

        return redirect()->route('booksync.setup.complete', ['setupToken' => $setupToken]);
    }

    /** Step 5: Show setup complete page. */
    public function complete(string $setupToken)
    {
        $merchant = BookSyncMerchant::where('setup_token', $setupToken)->firstOrFail();

        return view('booksync::setup.complete', compact('merchant'));
    }

    /** Edit: Re-show account selector pre-filled with current values. */
    public function edit(string $setupToken)
    {
        $merchant = BookSyncMerchant::where('setup_token', $setupToken)
            ->where('status', 'active')
            ->firstOrFail();

        $accounts  = $this->qb->fetchDepositAccounts($merchant);
        $items     = $this->qb->fetchItems($merchant);
        $customers = $this->qb->fetchCustomers($merchant);

        return view('booksync::setup.select-account', compact(
            'merchant', 'accounts', 'items', 'customers', 'setupToken'
        ) + ['editing' => true]);
    }
}
