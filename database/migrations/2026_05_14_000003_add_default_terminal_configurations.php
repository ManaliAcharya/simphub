<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'terminal' => 'valor',
                'credentials' => [
                    'api_key'  => env('VALOR_API_KEY', ''),
                    'base_url' => env('VALOR_BASE_URL', 'https://api.valorpaytech.com'),
                ],
            ],
            [
                'terminal' => 'dejavoo',
                'credentials' => [
                    'api_key'  => env('DEJAVOO_API_KEY', ''),
                    'base_url' => env('DEJAVOO_BASE_URL', 'https://api.dejavoo.com'),
                ],
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('terminal_configurations')
                ->where('merchant_id', 'default')
                ->where('terminal', $row['terminal'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('terminal_configurations')->insert([
                'id'                     => (string) Str::uuid(),
                'merchant_id'            => 'default',
                'terminal'               => $row['terminal'],
                'terminal_credentials'   => encrypt(json_encode($row['credentials'])),
                'is_active'              => 1,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('terminal_configurations')
            ->where('merchant_id', 'default')
            ->whereIn('terminal', ['valor', 'dejavoo'])
            ->delete();
    }
};
