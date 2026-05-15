<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('terminal_configurations')
            ->where('merchant_id', 'default')
            ->where('terminal', 'valor')
            ->first();

        if (! $row) {
            return;
        }

        $credentials = [
            'auth_token'        => env('VALOR_AUTH_TOKEN', ''),
            'app_id'            => env('VALOR_APP_ID', ''),
            'api_key'           => env('VALOR_API_KEY', ''),
            'epi'               => env('VALOR_EPI', '2319970727'),
            'base_url'          => env('VALOR_BASE_URL', 'https://valorpaytech.com'),
            'param_fetch_path'  => env('VALOR_PARAM_FETCH_PATH', '/api'),
        ];

        DB::table('terminal_configurations')
            ->where('id', $row->id)
            ->update([
                'terminal_credentials' => encrypt(json_encode($credentials)),
                'updated_at'           => now(),
            ]);
    }

    public function down(): void
    {
        $row = DB::table('terminal_configurations')
            ->where('merchant_id', 'default')
            ->where('terminal', 'valor')
            ->first();

        if (! $row) {
            return;
        }

        DB::table('terminal_configurations')
            ->where('id', $row->id)
            ->update([
                'terminal_credentials' => encrypt(json_encode([
                    'api_key'  => env('VALOR_API_KEY', ''),
                    'base_url' => env('VALOR_BASE_URL', 'https://valorpaytech.com'),
                ])),
                'updated_at' => now(),
            ]);
    }
};
