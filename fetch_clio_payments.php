<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = \Modules\Inbound\Models\PmsConnection::where('provider', 'clio')->latest()->first();

if (!$connection) {
    echo "No Clio connection found.\n";
    exit(1);
}

$oauth = app(\Modules\Inbound\Services\ClioOAuthService::class);
$clio  = \Modules\Inbound\Models\ClioConnection::find($connection->id);
$clio  = $oauth->ensureValidAccessToken($clio);
$base  = rtrim(config('services.clio.api_base_url'), '/');
$token = $clio->access_token;

$http = fn(string $path, array $query = []) =>
    \Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->get($base . $path, $query);

// Step 1: fetch with no fields filter - get raw default shape
echo "=== GET /api/v4/payments.json (no fields filter) ===\n";
$r = $http('/api/v4/payments.json', ['limit' => 1]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// Step 2: fetch with basic known-safe fields
echo "=== GET /api/v4/payments.json (basic fields) ===\n";
$r = $http('/api/v4/payments.json', ['fields' => 'id,date,amount,description', 'limit' => 3]);
echo "Status: {$r->status()}\n" . json_encode($r->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
