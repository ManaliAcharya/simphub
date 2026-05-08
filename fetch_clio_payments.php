<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = \Modules\Inbound\Models\PmsConnection::where('provider', 'clio')->latest()->first();

if (!$connection) {
    echo "No Clio connection found. Please reconnect Clio on the integration page first.\n";
    exit(1);
}

$oauth  = app(\Modules\Inbound\Services\ClioOAuthService::class);
$clio   = \Modules\Inbound\Models\ClioConnection::find($connection->id);
$clio   = $oauth->ensureValidAccessToken($clio);
$base   = rtrim(config('services.clio.api_base_url'), '/');
$token  = $clio->access_token;

$http = fn(string $path, array $query = []) =>
    \Illuminate\Support\Facades\Http::withToken($token)
        ->acceptJson()
        ->get($base . $path, $query);

echo "=== GET /api/v4/payments.json ===\n";
$r = $http('/api/v4/payments.json', ['fields' => 'id,date,amount,description,payment_type,voided_at,bank_account{id,name},allocations{id,amount,bill{id,number}}', 'limit' => 3]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== GET /api/v4/receive_payments.json ===\n";
$r = $http('/api/v4/receive_payments.json', ['limit' => 3]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== GET /api/v4/bill_payments.json ===\n";
$r = $http('/api/v4/bill_payments.json', ['limit' => 3]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== GET /api/v4/bills.json (last 3 with balance=0) ===\n";
$r = $http('/api/v4/bills.json', [
    'fields' => 'id,number,total,balance,state,payments{id,date,amount,payment_type,bank_account{id,name}}',
    'status' => 'paid',
    'limit'  => 3,
]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
