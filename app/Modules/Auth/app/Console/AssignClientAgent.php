<?php

namespace Modules\Auth\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Modules\Auth\Models\ClientAccount;
use Modules\Boarding\Models\BoardingClient;
use Modules\Inbound\Models\Client;

/**
 * Assigns an existing client to an agent (or removes the assignment).
 *
 *   php artisan client:assign-agent {client} agent@example.com
 *   php artisan client:assign-agent {client} --unassign
 *
 * {client} can be the client's id, its pms_client_id (Inbound clients) or
 * its client_id (Boarding clients, "iso_…"), or its login email.
 */
class AssignClientAgent extends Command
{
    protected $signature = 'client:assign-agent
        {client : Client id, pms_client_id, boarding client_id, or the client\'s login email}
        {agent? : Email of the agent user}
        {--unassign : Remove the client\'s agent instead}';

    protected $description = 'Assign an existing client to an agent, or remove its agent';

    public function handle(): int
    {
        $client = $this->findClient((string) $this->argument('client'));

        if (! $client) {
            $this->error('No client found for "' . $this->argument('client') . '".');

            return self::FAILURE;
        }

        $clientName = $client->client_name ?? $client->name;

        if ($this->option('unassign')) {
            $client->forceFill(['agent_id' => null])->save();
            $this->info("Removed the agent from {$clientName}.");

            return self::SUCCESS;
        }

        $email = $this->argument('agent');

        if (! $email) {
            $this->error('Give the agent\'s email, or pass --unassign.');

            return self::FAILURE;
        }

        $agent = User::query()->where('email', $email)->first();

        if (! $agent) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if ($agent->role !== UserRole::Agent) {
            $this->error("{$email} is not an agent (role: " . ($agent->role?->value ?? 'none') . ').');

            return self::FAILURE;
        }

        $client->forceFill(['agent_id' => $agent->getKey()])->save();
        $this->info("Assigned {$clientName} to agent {$agent->email}.");

        return self::SUCCESS;
    }

    private function findClient(string $key): ?Model
    {
        $client = Client::query()
            ->where('id', $key)
            ->orWhere('pms_client_id', $key)
            ->first();

        $client ??= BoardingClient::query()
            ->where('id', $key)
            ->orWhere('client_id', $key)
            ->first();

        if ($client || ! str_contains($key, '@')) {
            return $client;
        }

        // Fall back to the client's login email (ClientAccount owns the email).
        $account = ClientAccount::query()
            ->where('email_lower', strtolower($key))
            ->first();

        return $account?->owner;
    }
}
