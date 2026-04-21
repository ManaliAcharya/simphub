<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

class RegisterClioWebhookCommand extends Command
{
    protected $signature = 'clio:webhook:register {--pms-client-id=}';

    protected $description = 'Register or renew the Clio bill.created webhook.';

    public function handle(ClioOAuthService $oauth, ClioWebhookService $webhooks): int
    {
        try {
            $pmsClientId = (string) $this->option('pms-client-id');
            $connection = $oauth->ensureValidAccessToken(
                $pmsClientId !== ''
                    ? ClioConnection::query()->where('pms_client_id', $pmsClientId)->first()
                    : null
            );
            $webhook = $webhooks->registerInvoiceCreatedWebhook($connection);

            $this->components->info('Clio webhook registered.');
            $this->line('Webhook ID: '.($webhook['id'] ?? 'unknown'));
            $this->line('Webhook URL: '.($webhook['url'] ?? config('services.clio.webhook_callback_url')));
            $this->line('PMS Client ID: '.($connection->pms_client_id ?? 'unknown'));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
