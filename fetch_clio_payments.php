<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = \Modules\Inbound\Models\PmsConnection::where('provider', 'clio')->latest()->first();
if (!$connection) { echo "No Clio connection found.\n"; exit(1); }

$oauth = app(\Modules\Inbound\Services\ClioOAuthService::class);
$clio  = \Modules\Inbound\Models\ClioConnection::find($connection->id);
$clio  = $oauth->ensureValidAccessToken($clio);
$base  = rtrim(config('services.clio.api_base_url'), '/');
$token = $clio->access_token;

$get  = fn(string $path, array $q = []) =>
    \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->get($base . $path, $q);
$post = fn(string $path, array $body) =>
    \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->asJson()->post($base . $path, $body);

$paymentId = 656833493;

// 1. Probe individual payment with every plausible field set
$fieldSets = [
    'id,date,amount,description,currency,voided_at,received_at,created_at',
    'id,contact{id,name},matter{id,display_number},client{id,name}',
    'id,destination{id,name,type},source{id,name}',
    'id,type,method',
    'id,bills{id,number,amount}',
    'id,bill{id,number}',
    'id,lines{id,amount,description,bill{id,number}}',
    'id,interest,deposit',
];

foreach ($fieldSets as $fields) {
    $r = $get("/api/v4/payments/{$paymentId}.json", ['fields' => $fields]);
    echo "[{$r->status()}] fields={$fields}\n";
    if ($r->status() === 200) {
        echo json_encode($r->json()['data'] ?? $r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "\n";
}

// 2. POST with empty data to see required-field validation errors
echo "=== POST /api/v4/payments.json (empty — reveals required fields) ===\n";
$r = $post('/api/v4/payments.json', ['data' => []]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// 3. POST with plausible minimal body to see what's still missing
echo "=== POST /api/v4/payments.json (minimal probe) ===\n";
$r = $post('/api/v4/payments.json', ['data' => [
    'date'   => date('Y-m-d'),
    'amount' => 1.00,
]]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
