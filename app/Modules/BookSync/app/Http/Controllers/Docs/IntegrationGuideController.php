<?php

namespace Modules\BookSync\Http\Controllers\Docs;

use Illuminate\Routing\Controller;

class IntegrationGuideController extends Controller
{
    public function show()
    {
        return view('booksync::docs.integration-guide', [
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
