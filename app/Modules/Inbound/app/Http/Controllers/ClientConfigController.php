<?php

namespace Modules\Inbound\Http\Controllers;


use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use App\Support\PmsFeatures;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Auth\Services\InvitationService;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ZohoRegionResolver;
use Modules\Routing\Models\RoutingRule;
use Modules\Routing\Models\TerminalConfiguration;

class ClientConfigController extends Controller
{
    protected InvitationService $invitationService;
    public function __construct(InvitationService $invitationService)
    {
        $this->invitationService = $invitationService;

    }

    public function index(): View
    {
        $clients = Client::query()
            ->orderBy('client_name')
            ->get();

        return view('inbound::clients.index', compact('clients'));
    }

    public function create(ZohoRegionResolver $zohoRegions): View
    {
        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->filter()
            ->map(fn ($gateway) => strtoupper((string) $gateway))
            ->values()
            ->all();

        $availableTerminals = TerminalConfiguration::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('terminal')
            ->pluck('terminal')
            ->filter()
            ->map(fn ($terminal) => strtoupper((string) $terminal))
            ->values()
            ->all();

        return view('inbound::clients.create', [
            'clients'            => Client::query()->latest('created_at')->get(),
            'availableGateways'  => $availableGateways,
            'availableTerminals' => $availableTerminals,
            'zohoRegions'        => $zohoRegions->options(),
        ]);
    }

    public function store(Request $request, ZohoRegionResolver $zohoRegions): RedirectResponse
    {
        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('gateway')
            ->filter()
            ->map(fn ($gateway) => strtoupper((string) $gateway))
            ->values()
            ->all();

        $availableTerminals = TerminalConfiguration::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('terminal')
            ->filter()
            ->map(fn ($terminal) => strtoupper((string) $terminal))
            ->values()
            ->all();

        $isTerminal = ! empty($request->input('allowed_terminals'));

        $validated = $request->validate([
            'client_name'                => ['required', 'string', 'max:255'],

            'client_pms'                 => ['required', 'string', 'max:50'],
            'zoho_region'                => ['nullable', 'string', Rule::in(array_keys($zohoRegions->options()))],
            'allowed_payment_gateways'   => ! $isTerminal && $availableGateways !== []
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'allowed_payment_gateways.*' => ['string', Rule::in($availableGateways)],
            'allowed_terminals'          => ['nullable', 'array'],
            'allowed_terminals.*'        => $availableTerminals !== []
                ? ['string', Rule::in($availableTerminals)]
                : ['string'],
            'webhook_url'                => ['nullable', 'url', 'max:500'],
            'webhook_flow_enabled'       => ['nullable', 'boolean'],
            'call_api_to_pms'            => ['nullable', 'boolean'],
            'client_calls_our_api'       => ['nullable', 'boolean'],
            'client_email'               => ['required', 'email', 'max:255', 'unique:client_accounts,email_lower'],
        ]);

        if (strtoupper($validated['client_pms']) === 'ZOHO' && empty($validated['zoho_region'])) {
            return back()
                ->withErrors(['zoho_region' => 'Zoho region is required when PMS is Zoho.'])
                ->withInput();
        }

$isCustomPms = strtoupper($validated['client_pms']) === 'CUSTOM';

        $client = Client::query()->create([
            'pms_client_id'            => (string) Str::uuid(),
            'setup_token'              => strtolower(Str::random(12)),
            'webhook_secret'           => $isCustomPms ? 'whsec_' . Str::random(32) : null,
            'webhook_url'              => $isCustomPms ? ($validated['webhook_url'] ?? null) : null,
            'client_name'              => $validated['client_name'],
            'client_pms'               => strtoupper($validated['client_pms']),
            'zoho_region'              => strtoupper($validated['client_pms']) === 'ZOHO'
                ? $zohoRegions->normalize($validated['zoho_region'] ?? 'US')
                : null,
            'allowed_payment_gateways' => $isTerminal ? [] : collect($validated['allowed_payment_gateways'] ?? [])
                ->map(fn ($g) => strtoupper((string) $g))
                ->unique()->values()->all(),
            'allowed_terminals'        => $isTerminal
                ? collect($validated['allowed_terminals'])
                    ->map(fn ($t) => strtolower((string) $t))
                    ->unique()->values()->all()
                : [],
            'webhook_flow_enabled'  => ($isTerminal || strtoupper($validated['client_pms']) === 'CUSTOM') ? false : (bool) ($validated['webhook_flow_enabled'] ?? false),
            'call_api_to_pms'      => ($isTerminal || strtoupper($validated['client_pms']) === 'CUSTOM') ? false : (bool) ($validated['call_api_to_pms'] ?? false),
            'client_calls_our_api' => strtoupper($validated['client_pms']) === 'CUSTOM' ? true : ($isTerminal ? false : (bool) ($validated['client_calls_our_api'] ?? false)),
        ]);


        // Create a client account and send invitation email
        $this->invitationService->createInvitation([
            'client_id' => $client->id,
            'email' => $validated['client_email'],
            'admin_id' => 0
        ]);


        if ($isTerminal) {
            return redirect()->route('inbound.clients.terminal-created', [
                'pms_client_id' => $client->pms_client_id,
            ]);
        }

        $provider = match ($client->client_pms) {
            'ZOHO'        => 'zoho',
            'QUICKBOOKS'  => 'quickbooks',
            'WAVE'        => 'wave',
            'MINDBODY'    => 'mindbody',
            'ADVANCEDMD'  => 'advancedmd',
            'CUSTOM'      => 'custom',
            default       => 'clio',
        };

        return redirect()->route('inbound.clients.created', [
            'pms_client_id' => $client->pms_client_id,
            'provider'      => $provider,
        ]);
    }

    public function created(Request $request): View
    {
        $client = Client::query()
            ->where('pms_client_id', $request->query('pms_client_id'))
            ->firstOrFail();

        $provider = (string) $request->query('provider', 'clio');

        $shareUrl = $client->setup_token
            ? route('inbound.setup.share', ['provider' => $provider, 'token' => $client->setup_token])
            : null;

        return view('inbound::clients.created', compact('client', 'shareUrl', 'provider'));
    }

    public function redirectAction(string $pmsClientId): RedirectResponse
    {
        $connection = PmsConnection::query()
            ->where('pms_client_id', $pmsClientId)
            ->orderByDesc('updated_at')
            ->first();

        $provider = $connection?->provider ?? 'clio';

        $routeName = match ($provider) {
            'quickbooks' => 'inbound.quickbooks.page',
            'zoho'       => 'inbound.zoho.page',
            'lawcus'     => 'inbound.lawcus.page',
            'wave'       => 'inbound.wave.page',
            'mindbody'   => 'inbound.mindbody.page',
            'advancedmd' => 'inbound.advancedmd.page',
            'custom'     => 'inbound.clients.api-docs',
            default      => 'inbound.clio.page',
        };

        return redirect()->route($routeName, ['pms_client_id' => $pmsClientId]);
    }

    public function updateWebhookUrl(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $validated = $request->validate([
            'webhook_url' => ['nullable', 'url', 'max:500'],
        ]);

        $client->update(['webhook_url' => $validated['webhook_url'] ?? null]);

        return redirect()->route('inbound.clients.api-docs', [
            'pms_client_id' => $pmsClientId,
        ])->with('success', 'Webhook URL updated.');
    }

    public function updateGatewayCredentials(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $request->validate([
            'gateway_credentials'                      => ['nullable', 'array'],
            'gateway_credentials.environment'          => ['nullable', 'string', 'in:sandbox,production'],
            'gateway_credentials.fluidpay.api_key'    => ['nullable', 'string', 'max:500'],
            'gateway_credentials.fluidpay.public_key' => ['nullable', 'string', 'max:500'],
            'gateway_credentials.paya.username'       => ['nullable', 'string', 'max:255'],
            'gateway_credentials.paya.password'       => ['nullable', 'string', 'max:255'],
            'gateway_credentials.paya.terminal_id'    => ['nullable', 'string', 'max:50'],
            'gateway_credentials.nmi.security_key'    => ['nullable', 'string', 'max:500'],
            'gateway_credentials.nmi.public_key'      => ['nullable', 'string', 'max:500'],
        ]);

        $incoming = (array) ($request->input('gateway_credentials') ?? []);

        // Start from existing stored credentials so blank password fields (left intentionally
        // empty to keep the current value) do not overwrite saved private keys.
        try {
            $stored = (array) ($client->gateway_credentials ?? []);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $stored = [];
        }

        // Update common environment at the top level
        $env = trim((string) ($incoming['environment'] ?? ''));
        if ($env !== '') {
            $stored['environment'] = $env;
        }

        // Merge per-gateway credentials — only overwrite fields that are non-empty in the request
        foreach ($incoming as $gateway => $fields) {
            if ($gateway === 'environment' || ! is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if ($value !== null && trim((string) $value) !== '') {
                    $stored[$gateway][$key] = trim((string) $value);
                }
                // Empty value = keep existing (do not overwrite)
            }
        }

        $client->update([
            'gateway_credentials' => ! empty($stored) ? $stored : null,
        ]);

        return redirect()->back()->with('success', 'Gateway credentials saved.');
    }

    public function updateGatewayDisplayNames(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $validated = $request->validate([
            'gateway_display_names'              => ['nullable', 'array'],
            'gateway_display_names.*'            => ['nullable', 'string', 'max:60'],
        ]);

        $names = collect((array) ($validated['gateway_display_names'] ?? []))
            ->mapWithKeys(fn ($name, $gateway) => [strtolower((string) $gateway) => trim((string) $name)])
            ->filter(fn ($name) => $name !== '')
            ->all();

        $client->update([
            'gateway_display_names' => ! empty($names) ? $names : null,
        ]);

        return redirect()->back()->with('success', 'Payment method names saved.');
    }

    public function updateGateways(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('gateway')
            ->filter()
            ->map(fn ($g) => strtoupper((string) $g))
            ->values()
            ->all();

        $validated = $request->validate([
            'allowed_payment_gateways'   => ['required', 'array', 'min:1'],
            'allowed_payment_gateways.*' => $availableGateways !== []
                ? ['string', Rule::in($availableGateways)]
                : ['string'],
        ]);

        $client->update([
            'allowed_payment_gateways' => collect($validated['allowed_payment_gateways'] ?? [])
                ->map(fn ($g) => strtoupper((string) $g))
                ->unique()->values()->all(),
        ]);

        return redirect()->back()->with('success', 'Allowed gateways updated.');
    }

    public function toggleGatewayPause(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $validated = $request->validate([
            'gateway' => ['required', 'string'],
            'paused'  => ['required', 'boolean'],
        ]);

        $gateway = strtoupper((string) $validated['gateway']);
        $paused  = collect($client->paused_payment_gateways ?? [])
            ->map(fn ($g) => strtoupper((string) $g));

        if ($validated['paused']) {
            $paused = $paused->push($gateway)->unique()->values();
        } else {
            $paused = $paused->reject(fn ($g) => $g === $gateway)->values();
        }

        $client->update(['paused_payment_gateways' => $paused->all()]);

        return redirect()->back()->with('success', $gateway . ' gateway ' . ($validated['paused'] ? 'paused' : 'resumed') . '.');
    }

    public function updateFees(Request $request, string $pmsClientId): RedirectResponse
    {
        $client       = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();
        $allowedModes = PmsFeatures::for(strtolower((string) $client->client_pms))->feeModes();
        $isCd         = $request->input('fee_mode') === 'cash_discount';
        $feeOn        = (bool) $request->input('fee_surcharge_enabled');

        $validated = $request->validate([
            'fee_surcharge_enabled'  => ['nullable', 'boolean'],
            'fee_mode'               => ['nullable', 'string', Rule::in($allowedModes)],
            'cc_fee_percent'         => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'ach_fee_percent'        => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'fee_disclosure'         => [$isCd ? 'required' : 'nullable', 'string', 'max:1000'],
            'cd_business_name'       => [$isCd ? 'required' : 'nullable', 'string', 'max:255'],
            'cd_address'             => [$isCd ? 'required' : 'nullable', 'string', 'max:255'],
            'cd_city'                => [$isCd ? 'required' : 'nullable', 'string', 'max:100'],
            'cd_state'               => [$isCd ? 'required' : 'nullable', 'string', 'max:2'],
            'cd_zip'                 => [$isCd ? 'required' : 'nullable', 'string', 'max:10'],
            'cd_phone'               => [$isCd ? 'required' : 'nullable', 'string', 'max:30'],
            'cd_email'               => [$isCd ? 'required' : 'nullable', 'email', 'max:255'],
            'cd_instructions'        => ['nullable', 'string', 'max:500'],
            'cd_disclosure'          => ['nullable', 'string', 'max:500'],
        ]);

        // If fee is enabled, at least one gateway fee must be > 0
        if ($feeOn) {
            $ccFee  = (float) ($validated['cc_fee_percent'] ?? 0);
            $achFee = (float) ($validated['ach_fee_percent'] ?? 0);
            if ($ccFee <= 0 && $achFee <= 0) {
                return redirect()->back()
                    ->withErrors(['cc_fee_percent' => 'When processing fee is enabled, at least one gateway fee must be greater than 0.'])
                    ->withInput();
            }
        }

        $cashDetails = null;
        if (($validated['fee_mode'] ?? 'surcharge') === 'cash_discount') {
            $cashDetails = array_filter([
                'business_name' => $validated['cd_business_name'] ?? null,
                'address'       => $validated['cd_address'] ?? null,
                'city'          => $validated['cd_city'] ?? null,
                'state'         => $validated['cd_state'] ?? null,
                'zip'           => $validated['cd_zip'] ?? null,
                'phone'         => $validated['cd_phone'] ?? null,
                'email'         => $validated['cd_email'] ?? null,
                'instructions'  => $validated['cd_instructions'] ?? null,
                'disclosure'    => $validated['cd_disclosure'] ?? null,
            ], fn($v) => $v !== null && $v !== '');
        }

        $client->update([
            'fee_surcharge_enabled'  => (bool) ($validated['fee_surcharge_enabled'] ?? false),
            'fee_mode'               => $validated['fee_mode'] ?? 'surcharge',
            'cc_fee_percent'         => $validated['cc_fee_percent'] ?? null,
            'ach_fee_percent'        => $validated['ach_fee_percent'] ?? null,
            'fee_disclosure'         => $validated['fee_disclosure'] ?? null,
            'cash_discount_details'  => !empty($cashDetails) ? $cashDetails : null,
        ]);

        return redirect()->back()->with('success', 'Processing fee configuration saved.');
    }

    public function updateQbSettings(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $validated = $request->validate([
            'qb_fee_override_enabled' => ['nullable', 'boolean'],
            'qb_fee_override_field'   => ['nullable', 'string', 'max:100'],
            'qb_multi_mid_enabled'    => ['nullable', 'boolean'],
        ]);

        $client->update([
            'qb_fee_override_enabled' => (bool) ($validated['qb_fee_override_enabled'] ?? false),
            'qb_fee_override_field'   => $validated['qb_fee_override_field'] ?? 'Cash Discount',
            'qb_multi_mid_enabled'    => (bool) ($validated['qb_multi_mid_enabled'] ?? false),
        ]);

        return redirect()->back()->with('success', 'QuickBooks settings saved.');
    }

    public function saveMidRoutes(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        // Save the toggle flag immediately — independent of route validation
        $client->update([
            'qb_multi_mid_enabled' => (bool) $request->boolean('qb_multi_mid_enabled'),
        ]);

        $request->validate([
            'routes'                        => ['nullable', 'array'],
            'routes.*.route_type'           => ['nullable', 'string', Rule::in(['fees_on', 'fees_off'])],
            'routes.*.gateway'              => ['nullable', 'string', 'max:50'],
            'routes.*.mid_identifier'       => ['nullable', 'string', 'max:100'],
            'routes.*.mid_label'            => ['nullable', 'string', 'max:255'],
            'routes.*.rate_percent'         => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'routes.*.environment'          => ['nullable', 'string', Rule::in(['sandbox', 'production'])],
            'routes.*.credentials'          => ['nullable', 'array'],
            'routes.*.credentials.*'        => ['nullable', 'string', 'max:1000'],
        ]);

        // Use $request->input() for routes to ensure nested credential keys are not stripped
        $routes = $request->input('routes', []);

        foreach ($routes as $route) {
            // Skip routes with no MID identifier filled in yet
            if (empty($route['mid_identifier'])) continue;

            $gateway   = strtolower($route['gateway']);
            $routeType = $route['route_type'];

            // Load existing route to merge credentials (preserve fields not re-entered)
            $existingRoute = ClientMidRoute::query()
                ->where('client_id',  $client->id)
                ->where('route_type', $routeType)
                ->where('gateway',    $gateway)
                ->first();

            try {
                $mergedCredentials = (array) ($existingRoute?->credentials ?? []);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                $mergedCredentials = [];
            }

            // Merge: only non-empty submitted values override existing
            foreach ((array) ($route['credentials'] ?? []) as $k => $v) {
                if ($v !== null && trim((string) $v) !== '') {
                    $mergedCredentials[$k] = trim((string) $v);
                }
            }

            ClientMidRoute::updateOrCreate(
                [
                    'client_id'  => $client->id,
                    'route_type' => $routeType,
                    'gateway'    => $gateway,
                ],
                [
                    'mid_identifier' => $route['mid_identifier'],
                    'mid_label'      => $route['mid_label'] ?? null,
                    'rate_percent'   => $route['rate_percent'] ?? null,
                    'environment'    => $route['environment'] ?? 'sandbox',
                    'credentials'    => !empty($mergedCredentials) ? $mergedCredentials : null,
                    'is_active'      => true,
                ]
            );
        }

        return redirect()->back()->with('success', 'MID routes saved successfully.');
    }

    public function uploadLogo(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        if ($client->logo_path) {
            Storage::disk('public')->delete($client->logo_path);
        }

        $path = $request->file('logo')->store("client-logos/{$pmsClientId}", 'public');

        $client->update(['logo_path' => $path]);

        return redirect()->back()->with('success', 'Logo uploaded successfully.');
    }

    public function removeLogo(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        if ($client->logo_path) {
            Storage::disk('public')->delete($client->logo_path);
            $client->update(['logo_path' => null]);
        }

        return redirect()->back()->with('success', 'Logo removed.');
    }

    public function updateNotificationSettings(Request $request, string $pmsClientId): RedirectResponse
    {
        $client  = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();
        $enabled = (bool) $request->input('payment_link_override_enabled');

        $validated = $request->validate([
            'payment_link_override_enabled' => ['nullable', 'boolean'],
            'payment_link_recipient'        => ['nullable', 'string', Rule::in(['admin', 'both'])],
            'payment_link_admin_email'      => [
                $enabled && in_array($request->input('payment_link_recipient'), ['admin', 'both'], true)
                    ? 'required' : 'nullable',
                'email',
                'max:255',
            ],
        ]);

        $recipient = $validated['payment_link_recipient'] ?? 'admin';

        $client->update([
            'payment_link_override_enabled' => $enabled,
            'payment_link_recipient'        => $recipient,
            'payment_link_admin_email'      => $enabled && in_array($recipient, ['admin', 'both'], true)
                ? $validated['payment_link_admin_email']
                : null,
        ]);

        return redirect()->back()->with('success', 'Payment link settings saved.');
    }

    public function updateEmailConfig(Request $request, string $pmsClientId): RedirectResponse
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->firstOrFail();

        $validated = $request->validate([
            'subject_template'         => ['nullable', 'string', 'max:500'],
            'subject_template_updated' => ['nullable', 'string', 'max:500'],
            'body_header'              => ['nullable', 'string', 'max:5000'],
            'body_footer'              => ['nullable', 'string', 'max:5000'],
            'primary_color'            => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'reply_to_email'           => ['nullable', 'email', 'max:255'],
            'reply_to_name'            => ['nullable', 'string', 'max:255'],
            'attach_pdf'               => ['nullable', 'in:0,1'],
        ]);

        EmailConfiguration::updateOrCreate(
            ['client_id' => $client->id],
            [
                'subject_template'         => $validated['subject_template'] ?: 'Invoice #{invoice_number} – Payment Required',
                'subject_template_updated' => $validated['subject_template_updated'] ?: 'Updated: Invoice #{invoice_number} – Payment Required',
                'body_header'              => $validated['body_header'] ?: null,
                'body_footer'              => $validated['body_footer'] ?: null,
                'primary_color'            => $validated['primary_color'] ?? '#2196F3',
                'reply_to_email'           => $validated['reply_to_email'] ?: null,
                'reply_to_name'            => $validated['reply_to_name'] ?: null,
                'attach_pdf'               => ($validated['attach_pdf'] ?? '1') === '1',
            ]
        );

        return redirect()->back()->with('success', 'Email settings saved.');
    }
}
