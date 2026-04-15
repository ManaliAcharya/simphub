<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

class RegisterClioWebhookCommand extends Command
{
    protected $signature = 'clio:webhook:register';

    protected $description = 'Register or renew the Clio bill.created webhook.';

    public function handle(ClioOAuthService $oauth, ClioWebhookService $webhooks): int
    {
        try {
            $connection = $oauth->ensureValidAccessToken();
            $webhook = $webhooks->registerInvoiceCreatedWebhook($connection);

            $this->components->info('Clio webhook registered.');
            $this->line('Webhook ID: '.($webhook['id'] ?? 'unknown'));
            $this->line('Webhook URL: '.($webhook['url'] ?? config('services.clio.webhook_callback_url')));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
