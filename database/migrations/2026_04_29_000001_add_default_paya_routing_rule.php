<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('routing_rules')
            ->where('merchant_id', 'default')
            ->where('gateway', 'paya')
            ->where('mid', 'paya-sandbox-mid')
            ->where('payment_method', 'ACH')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('routing_rules')->insert([
            'id' => (string) Str::uuid(),
            'merchant_id' => 'default',
            'gateway' => 'paya',
            'mid' => 'paya-sandbox-mid',
            'mid_credentials' => encrypt([
                'username' => env('PAYA_USERNAME', 'ImpactPaysCert'),
                'password' => env('PAYA_PASSWORD', ''),
                'terminal_id' => env('PAYA_TERMINAL_ID', '1814'),
                'wsdl' => env('PAYA_WSDL_PATH', 'C:\\laragon\\www\\payment-middleware\\paya_payment\\AuthGatewayWSDL-Demo.eftchecks.com.xml'),
                'namespace' => env('PAYA_NAMESPACE', 'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway'),
                'terminal_settings_method' => env('PAYA_TERMINAL_SETTINGS_METHOD', 'GetCertificationTerminalSettings'),
                'process_method' => env('PAYA_PROCESS_METHOD', 'ProcessSingleCertificationCheck'),
                'payment_info' => [
                    'RoutingNumber' => env('PAYA_ROUTING_NUMBER', '490000018'),
                    'AccountNumber' => env('PAYA_ACCOUNT_NUMBER', '123456789'),
                    'CheckNumber' => env('PAYA_CHECK_NUMBER', '11111'),
                    'FirstName' => env('PAYA_FIRST_NAME', 'Sandbox'),
                    'LastName' => env('PAYA_LAST_NAME', 'Payer'),
                    'Address1' => env('PAYA_ADDRESS1', '123 Demo Street'),
                    'Address2' => env('PAYA_ADDRESS2', 'Suite 100'),
                    'City' => env('PAYA_CITY', 'Memphis'),
                    'State' => env('PAYA_STATE', 'TN'),
                    'Zip' => env('PAYA_ZIP', '38103'),
                    'PhoneNumber' => env('PAYA_PHONE_NUMBER', '9015551212'),
                    'DLState' => env('PAYA_DL_STATE', 'TN'),
                    'DLNumber' => env('PAYA_DL_NUMBER', '12345'),
                    'Identifier' => env('PAYA_IDENTIFIER', 'A'),
                ],
            ]),
            'payment_method' => 'ACH',
            'fund_type' => 'ANY',
            'min_amount_cents' => 0,
            'max_amount_cents' => null,
            'priority' => 1,
            'is_fallback' => 0,
            'daily_volume_limit_cents' => null,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('routing_rules')
            ->where('merchant_id', 'default')
            ->where('gateway', 'paya')
            ->where('mid', 'paya-sandbox-mid')
            ->where('payment_method', 'ACH')
            ->delete();
    }
};
