<?php

namespace App\Support;

/**
 * US state / territory names to their 2-letter USPS codes. Browsers often autofill the
 * full name ("Florida"), while gateways expect the code ("FL") for AVS.
 */
class UsState
{
    private const CODES = [
        'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR',
        'CALIFORNIA' => 'CA', 'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE',
        'DISTRICT OF COLUMBIA' => 'DC', 'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI',
        'IDAHO' => 'ID', 'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA',
        'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA', 'MAINE' => 'ME',
        'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE',
        'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM',
        'NEW YORK' => 'NY', 'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH',
        'OKLAHOMA' => 'OK', 'OREGON' => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI',
        'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX',
        'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA',
        'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        'AMERICAN SAMOA' => 'AS', 'GUAM' => 'GU', 'NORTHERN MARIANA ISLANDS' => 'MP',
        'PUERTO RICO' => 'PR', 'U.S. VIRGIN ISLANDS' => 'VI', 'US VIRGIN ISLANDS' => 'VI',
        'VIRGIN ISLANDS' => 'VI', 'ARMED FORCES AMERICAS' => 'AA', 'ARMED FORCES EUROPE' => 'AE',
        'ARMED FORCES PACIFIC' => 'AP',
    ];

    /**
     * The 2-letter code for a state name or code (any case, extra spaces ignored).
     * Anything unrecognised is returned trimmed, so validation can reject it.
     */
    public static function toCode(?string $value): string
    {
        $value = trim((string) $value);
        $key = strtoupper((string) preg_replace('/\s+/', ' ', $value));

        if (in_array($key, self::CODES, true)) {
            return $key;
        }

        return self::CODES[$key] ?? $value;
    }
}
