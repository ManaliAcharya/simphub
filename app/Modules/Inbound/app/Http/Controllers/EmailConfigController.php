<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;

class EmailConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');
        $config = EmailConfiguration::where('client_id', $client->id)->first();

        return response()->json($this->format($client, $config));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $validated = $request->validate([
            'subject_template'         => 'sometimes|string|max:500',
            'subject_template_updated' => 'sometimes|string|max:500',
            'body_header'              => 'sometimes|nullable|string|max:5000',
            'body_footer'              => 'sometimes|nullable|string|max:5000',
            'primary_color'            => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'reply_to_email'           => 'sometimes|nullable|email|max:255',
            'reply_to_name'            => 'sometimes|nullable|string|max:255',
            'from_name'                => 'sometimes|nullable|string|max:255',
            'attach_pdf'               => 'sometimes|boolean',
        ]);

        $config = EmailConfiguration::updateOrCreate(
            ['client_id' => $client->id],
            $validated,
        );

        return response()->json($this->format($client, $config));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $request->validate([
            'logo' => 'required|file|mimes:png,jpg,jpeg,svg|max:512',
        ]);

        $path    = $request->file('logo')->store("email-logos/{$client->id}", 'public');
        $logoUrl = rtrim(config('app.url'), '/') . '/storage/' . $path;

        EmailConfiguration::updateOrCreate(
            ['client_id' => $client->id],
            ['logo_url'  => $logoUrl],
        );

        return response()->json(['logo_url' => $logoUrl]);
    }

    private function format(Client $client, ?EmailConfiguration $config): array
    {
        return [
            'client_id'                => $client->id,
            'subject_template'         => $config?->subject_template         ?? 'Invoice #{invoice_number} – Payment Required',
            'subject_template_updated' => $config?->subject_template_updated ?? 'Updated: Invoice #{invoice_number} – Payment Required',
            'body_header'              => $config?->body_header,
            'body_footer'              => $config?->body_footer,
            'logo_url'                 => $config?->logo_url,
            'primary_color'            => $config?->primary_color             ?? '#2196F3',
            'reply_to_email'           => $config?->reply_to_email,
            'reply_to_name'            => $config?->reply_to_name,
            'from_name'                => $config?->from_name,
            'attach_pdf'               => $config?->attach_pdf               ?? true,
        ];
    }
}
