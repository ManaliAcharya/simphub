<?php

namespace App\Support;

use Modules\Inbound\Models\PmsFeatureFlag;

class PmsFeatures
{
    private function __construct(private readonly array $resolved) {}

    /**
     * Resolve feature flags for the given provider from the database (cached).
     * Provider string is normalised to lowercase before the lookup.
     */
    public static function for(string $provider): self
    {
        $provider = strtolower($provider);
        $features = config('pms_features.features', []);

        $resolved = [];
        foreach ($features as $key => $meta) {
            $default        = (bool) ($meta['default'] ?? true);
            $resolved[$key] = PmsFeatureFlag::isEnabled($provider, $key, $default);
        }

        return new self($resolved);
    }

    /**
     * Allowed fee_mode values for this provider.
     *
     * @return string[]
     */
    public function feeModes(): array
    {
        $modes = ['surcharge'];
        if ($this->resolved['cash_discount_mode'] ?? true) {
            $modes[] = 'cash_discount';
        }
        return $modes;
    }

    /**
     * Generic check — is the given feature enabled for this provider?
     * Returns true when the feature key is not defined in config.
     */
    public function allows(string $feature, string $value = ''): bool
    {
        if ($feature === 'fee_modes') {
            return $value === '' || in_array($value, $this->feeModes(), true);
        }
        return (bool) ($this->resolved[$feature] ?? true);
    }
}
