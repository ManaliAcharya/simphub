<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ZohoCrmApiClient;
use Modules\Inbound\Services\ZohoCrmOAuthService;
use Throwable;

class ZohoCrmFindContactCommand extends Command
{
    protected $signature = 'zohocrm:find-contact
        {client : pms_client_id of the Zoho CRM connection}
        {email : Customer email to look up}';

    protected $description = 'Show each step of the Zoho CRM contact lookup used when a subscription payment is recorded.';

    public function handle(ZohoCrmOAuthService $oauth, ZohoCrmApiClient $api): int
    {
        $connection = PmsConnection::query()
            ->where('provider', ZohoCrmOAuthService::PROVIDER)
            ->where('pms_client_id', $this->argument('client'))
            ->latest('created_at')
            ->first();

        if (! $connection) {
            $this->components->error('No Zoho CRM connection for that client.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Connected / updated', $connection->created_at.' / '.$connection->updated_at);
        $this->components->twoColumnDetail('API domain', (string) data_get($connection->meta, 'api_domain', '-'));

        try {
            $connection = $oauth->ensureValidAccessToken($connection);
        } catch (Throwable $e) {
            $this->components->error('Token could not be refreshed: '.$e->getMessage());

            return self::FAILURE;
        }

        $steps = [
            'Org' => fn () => json_encode(array_intersect_key(
                $api->fetchOrganization($connection),
                array_flip(['company_name', 'domain_name', 'zgid', 'id'])
            )),
            'Search API' => fn () => json_encode($api->searchContactsByEmail($connection, $this->argument('email'))),
            'COQL' => fn () => json_encode($api->queryContactsByEmail($connection, $this->argument('email'))),
        ];

        foreach ($steps as $label => $step) {
            try {
                $this->components->twoColumnDetail($label, $step());
            } catch (Throwable $e) {
                $this->components->twoColumnDetail($label, '<fg=red>'.mb_substr($e->getMessage(), 0, 400).'</>');
            }
        }

        return self::SUCCESS;
    }
}
