<?php

namespace Modules\BookSync\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Modules\BookSync\Models\BookSyncClient;

class ApiDocsController extends Controller
{
    public function show(string $clientId)
    {
        $client = BookSyncClient::where('client_id', $clientId)->firstOrFail();

        return view('booksync::admin.api-docs', [
            'client'  => $client,
            'apiKey'  => $client->client_api_key,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
