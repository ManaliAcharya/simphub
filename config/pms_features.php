<?php

return [

    /*
     * All known PMS providers.
     * Key   = lowercase provider slug (matches client_pms stored value, lowercased)
     * Value = human-readable label shown in the settings UI
     */
    'providers' => [
        'quickbooks' => 'QuickBooks',
        'clio'       => 'Clio',
        'zoho'       => 'Zoho Books',
        'lawcus'     => 'Lawcus',
        'wave'       => 'Wave',
        'mindbody'   => 'Mindbody',
        'advancedmd' => 'AdvancedMD',
        'custom'     => 'Custom / API',
    ],

    /*
     * Feature registry — all configurable feature flags.
     * Key     = machine identifier stored in pms_feature_flags.feature
     * label   = short name shown in the settings table header
     * description = tooltip / helper text
     * default = true means the feature is ON for any provider that has no DB row
     */
    'features' => [
        'cash_discount_mode' => [
            'label'       => 'Cash Discount (Pay by Cash/Check)',
            'description' => 'Allow merchants to configure a cash/check offline payment option at checkout.',
            'default'     => true,
        ],
        // Add new feature flags here — no code changes needed, just a new key.
    ],

];
