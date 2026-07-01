<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Configuration
    |--------------------------------------------------------------------------
    |
    | max_attempts:
    |   Maximum number of requests allowed within the decay window.
    |
    | decay_seconds:
    |   Time window (in seconds) before the request count resets.
    |
    */

    'global' => [
        // 300 requests per minute per IP
        'max_attempts' => 300,
        'decay_seconds' => 60,
    ],

    'auth' => [

        'login' => [
            // 5 login attempts per 15 minutes per email + IP
            'max_attempts' => 50,
            'decay_seconds' => 900,
        ],

        'forgot_password' => [

            'request' => [
                // 3 reset requests per hour per email + IP
                'max_attempts' => 10,
                'decay_seconds' => 3600,
            ],

            'verify' => [
                // 10 OTP/code verification attempts per 15 minutes
                'max_attempts' => 10,
                'decay_seconds' => 900,
            ],

            'reset' => [
                // 3 password reset submissions per 15 minutes
                'max_attempts' => 3,
                'decay_seconds' => 900,
            ],
        ],
    ],
    'reauth' => [
        'window_minutes' => 5,
        'max_attempts' => 10,
        'decay_minutes' => 15,
    ]
];
