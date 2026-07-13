<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PmsFeatureFlag extends Model
{
    protected $fillable = ['provider', 'feature', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public static function cacheKey(string $provider, string $feature): string
    {
        return "pms_feature:{$provider}:{$feature}";
    }

    public static function isEnabled(string $provider, string $feature, bool $default = true): bool
    {
        return Cache::remember(
            static::cacheKey($provider, $feature),
            now()->addMinutes(5),
            fn () => static::query()
                ->where('provider', $provider)
                ->where('feature', $feature)
                ->value('enabled') ?? $default,
        );
    }

    public static function clearCache(string $provider, string $feature): void
    {
        Cache::forget(static::cacheKey($provider, $feature));
    }
}
