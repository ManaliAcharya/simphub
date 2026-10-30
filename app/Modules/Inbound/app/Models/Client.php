<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Auth\Models\ClientAccount;
use Modules\Outbound\Services\PayaAchStatusService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasUuids;

    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $guarded = [];

    protected $hidden = ['gateway_credentials', 'trust_gateway_credentials', 'subscription_api_key_hash'];

    protected function casts(): array
    {
        return [
            'webhook_flow_enabled'     => 'boolean',
            'call_api_to_pms'          => 'boolean',
            'client_calls_our_api'     => 'boolean',
            'allowed_payment_gateways' => 'array',
            'paused_payment_gateways'  => 'array',
            'gateway_credentials'      => 'encrypted:array',
            // IOLTA (trust) MID per gateway — same shape as gateway_credentials, see gatewayCredentialsFor().
            'trust_gateway_credentials' => 'encrypted:array',
            'gateway_display_names'    => 'array',
            'allowed_terminals'        => 'array',
            'fee_surcharge_enabled'       => 'boolean',
            'cash_discount_details'       => 'array',
            // decimal:5 (not float) — exact decimal arithmetic on this value matters: it's the
            // surcharge/cash-discount rate used to compute IOLTA trust deposits down to the cent.
            'cc_fee_percent'              => 'decimal:5',
            'ach_fee_percent'             => 'decimal:5',
            'exact_cent_fee_rounding_enabled' => 'boolean',
            'qb_fee_override_enabled'        => 'boolean',
            'qb_multi_mid_enabled'           => 'boolean',
            'ready_to_send_enabled'          => 'boolean',
            'qb_surcharge_enabled'           => 'boolean',
            'qb_use_native_pdf'              => 'boolean',
            'wave_surcharge_enabled'         => 'boolean',
            'payment_link_override_enabled'  => 'boolean',
            'reminders_enabled'              => 'boolean',
            'reminder_schedule_days'         => 'array',
            'subscription_api_key_created_at' => 'datetime',
            'lawcus_send_trust_links'         => 'boolean',
            'lawcus_ach_settlement_notify'    => 'boolean',
            'clio_send_trust_links'           => 'boolean',
        ];
    }

    public function emailConfiguration(): HasOne
    {
        return $this->hasOne(EmailConfiguration::class, 'client_id');
    }

    public function usesTerminal(): bool
    {
        return ! empty($this->allowed_terminals);
    }

    /**
     * Last-resort fallback if config('reminders.default_schedule_days') itself is
     * missing/null — e.g. config/reminders.php not deployed, or a stale config
     * cache from before it existed. Keeps this accessor from ever 500ing regardless
     * of deployment state; the config value is still preferred when it's valid.
     */
    private const FALLBACK_SCHEDULE_DAYS = [3, 7, 14, 30, 45];

    private static function defaultScheduleDays(): array
    {
        $configured = config('reminders.default_schedule_days');

        return is_array($configured) && $configured !== [] ? $configured : self::FALLBACK_SCHEDULE_DAYS;
    }

    /**
     * Effective reminder cadence as an array of ints, falling back to the system
     * default. Tolerates reminder_schedule_days having been stored as a raw
     * comma string rather than a JSON array (e.g. from data written before/outside
     * the normal save path) — the array cast doesn't error on that, it just leaves
     * the value as a string, which used to blow up every count()/implode() caller.
     */
    public function reminderScheduleDays(): array
    {
        $days = $this->reminder_schedule_days;

        if (is_string($days)) {
            $days = array_map(static fn ($d) => (int) trim($d), explode(',', $days));
        }

        if (! is_array($days) || $days === []) {
            return self::defaultScheduleDays();
        }

        $days = array_values(array_filter($days, static fn ($d) => is_numeric($d) && (int) $d > 0));

        return $days !== [] ? $days : self::defaultScheduleDays();
    }

    public function gatewayDisplayName(string $gateway): string
    {
        $names = (array) ($this->gateway_display_names ?? []);
        $name  = trim((string) ($names[strtolower($gateway)] ?? ''));

        return $name !== '' ? $name : strtoupper($gateway);
    }

    /**
     * Lawcus and Clio clients send Trust Requests and configure their IOLTA MID here, so their
     * trust money must never fall back to the operating credentials — a trust payment without
     * one is refused.
     */
    public function requiresIoltaMid(): bool
    {
        return in_array(strtoupper((string) $this->client_pms), ['LAWCUS', 'CLIO'], true);
    }

    /** The keys an IOLTA MID needs, since trust money is charged with these alone. */
    private const IOLTA_KEYS = [
        'fluidpay' => ['api_key', 'public_key'],
        'paya'     => ['username', 'password', 'terminal_id'],
        'nmi'      => ['security_key', 'public_key'],
    ];

    /**
     * Whether an IOLTA MID is set up for this gateway: all of its own keys. Trust money never
     * borrows the operating keys, so without them the trust payment is refused.
     */
    public function hasIoltaMid(string $gateway): bool
    {
        $gateway = strtolower($gateway);
        $trust   = (array) (((array) ($this->trust_gateway_credentials ?? []))[$gateway] ?? []);

        return isset(self::IOLTA_KEYS[$gateway])
            && collect(self::IOLTA_KEYS[$gateway])->every(fn ($key) => trim((string) ($trust[$key] ?? '')) !== '');
    }

    /** Fields that pick one specific MID, so they never carry over from the operating set to trust. */
    private const MID_SELECTOR_KEYS = ['mid_identifier', 'processor_id'];

    /**
     * Lawcus ACH (Paya) payments are followed to settlement: the firm hears about them once
     * they settle, or is told to reverse them in Lawcus if they're returned. Off everywhere
     * until Paya gives us a settlement status endpoint (PAYA_STATUS_WSDL / _METHOD).
     */
    public function tracksAchSettlement(string $gateway): bool
    {
        return app(PayaAchStatusService::class)->isConfigured()
            && strtoupper((string) $this->client_pms) === 'LAWCUS'
            && (bool) $this->lawcus_ach_settlement_notify
            && strtolower($gateway) === 'paya';
    }

    /**
     * This client's Gateway Credentials for one gateway and fund type, with the common
     * environment applied. A client with an IOLTA MID pays trust money with the IOLTA set
     * alone — never the operating keys. Other clients' trust money keeps the operating keys,
     * but never the operating MID or processor ID.
     */
    public function gatewayCredentialsFor(string $gateway, ?string $fundType): array
    {
        $gateway = strtolower($gateway);
        $all     = (array) ($this->gateway_credentials ?? []);
        $creds   = (array) ($all[$gateway] ?? []);

        if (strtoupper((string) $fundType) === 'TRUST') {
            $trust = (array) (((array) ($this->trust_gateway_credentials ?? []))[$gateway] ?? []);
            $creds = $this->requiresIoltaMid()
                ? $trust
                : array_merge(array_diff_key($creds, array_flip(self::MID_SELECTOR_KEYS)), $trust);
        }

        if (! empty($all['environment'])) {
            $creds['environment'] = $all['environment'];
        }

        return $creds;
    }

    /**
     * Issues a new subscription API key, replacing any previous one. Only the hash
     * is stored, so the returned plain key must be shown to the user immediately.
     */
    public function issueSubscriptionApiKey(): string
    {
        $plainKey = 'shk_'.Str::random(48);

        $this->forceFill([
            'subscription_api_key_hash'       => self::hashSubscriptionApiKey($plainKey),
            'subscription_api_key_last4'      => substr($plainKey, -4),
            'subscription_api_key_created_at' => now(),
        ])->save();

        return $plainKey;
    }

    /** Day of the month subscription links go out (1-31); defaults to the 1st. */
    public function subscriptionBillingDay(): int
    {
        $day = (int) ($this->subscription_billing_day ?? 1);

        return $day >= 1 && $day <= 31 ? $day : 1;
    }

    public static function hashSubscriptionApiKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public function account(): MorphOne
    {
        return $this->morphOne(ClientAccount::class, 'owner');
    }
}
