<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\PmsFeatureFlag;

class PmsFeatureSettingsController extends Controller
{
    public function show(): View
    {
        $providers = config('pms_features.providers', []);
        $features  = config('pms_features.features', []);

        // Load all existing DB rows keyed as "provider:feature"
        $flags = PmsFeatureFlag::all()
            ->keyBy(fn (PmsFeatureFlag $f) => "{$f->provider}:{$f->feature}");

        // Build matrix[provider][feature] = bool
        $matrix = [];
        foreach (array_keys($providers) as $provider) {
            foreach ($features as $key => $meta) {
                $flag = $flags->get("{$provider}:{$key}");
                $matrix[$provider][$key] = $flag !== null
                    ? $flag->enabled
                    : (bool) ($meta['default'] ?? true);
            }
        }

        return view('inbound::pms-feature-settings', compact('providers', 'features', 'matrix'));
    }

    public function update(Request $request): RedirectResponse
    {
        $providers = config('pms_features.providers', []);
        $features  = config('pms_features.features', []);

        foreach (array_keys($providers) as $provider) {
            foreach (array_keys($features) as $key) {
                $enabled = (bool) $request->boolean("flags.{$provider}.{$key}");

                PmsFeatureFlag::updateOrCreate(
                    ['provider' => $provider, 'feature' => $key],
                    ['enabled'  => $enabled],
                );

                PmsFeatureFlag::clearCache($provider, $key);
            }
        }

        return redirect()->back()->with('success', 'Feature settings saved.');
    }
}
