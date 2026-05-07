<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Models\PmsConnection;
use RuntimeException;

class ZohoWebhookSetupService
{
    public function __construct(
        private readonly ZohoApiClient $api,
    ) {}

    /**
     * Creates a Zoho Books webhook and a workflow rule that fires it on invoice creation.
     * Fetches the existing "When an invoice is created" workflow as a structural reference
     * so our instant_actions matches the format Zoho accepts.
     * Returns the created webhook_id and workflow_id.
     */
    public function setup(PmsConnection $connection, string $pmsClientId): array
    {
        $organizationId = (string) data_get($connection->meta, 'default_organization_id', '');

        if ($organizationId === '') {
            throw new RuntimeException('Cannot auto-configure webhook: Zoho organization ID was not resolved during authentication.');
        }

        $webhookUrl = rtrim((string) config('services.zoho.webhook_callback_url'), '/');

        $rawBody = json_encode([
            'pms_client_id' => $pmsClientId,
            'invoice_id' => '${INVOICE.INVOICE_ID}',
            'total_amount' => '${INVOICE.INVOICE_TOTAL}',
            'status' => '${INVOICE.STATUS}',
            'source' => 'zoho',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $webhookResponse = $this->api->createWebhook($connection, $organizationId, [
            'webhook_name' => 'Payment Middleware – Invoice Notify',
            'description' => 'Notifies the payment middleware when an invoice is created or updated.',
            'url' => $webhookUrl,
            'method' => 'POST',
            'body_type' => 'application/json',
            'entity' => 'invoice',
            'raw_data' => $rawBody,
        ]);

        $webhookId = (string) (
            data_get($webhookResponse, 'webhook.webhook_id')
            ?? data_get($webhookResponse, 'webhook_id')
            ?? ''
        );

        if ($webhookId === '') {
            throw new RuntimeException('Zoho did not return a webhook ID after creation. Response: '.json_encode($webhookResponse));
        }

        $instantActions = $this->resolveInstantActions($connection, $organizationId, $webhookId, $webhookUrl, $rawBody);

        $workflowResponse = $this->api->createWorkflow($connection, $organizationId, [
            'workflow_name' => 'Payment Middleware – Invoice Created',
            'entity' => 'invoice',
            'rule_type' => 'add',
            'instant_actions' => $instantActions,
        ]);

        $workflowId = (string) (
            data_get($workflowResponse, 'workflow.workflow_id')
            ?? data_get($workflowResponse, 'workflow_id')
            ?? ''
        );

        return [
            'webhook_id' => $webhookId,
            'workflow_id' => $workflowId,
        ];
    }

    /**
     * Fetches the existing "When an invoice is created" workflow and mirrors its
     * instant_actions structure, replacing only the webhook details with ours.
     * Falls back to a sensible default if the reference workflow cannot be found.
     */
    private function resolveInstantActions(
        PmsConnection $connection,
        string $organizationId,
        string $webhookId,
        string $webhookUrl,
        string $rawBody,
    ): array {
        try {
            $workflowsResponse = $this->api->fetchWorkflows($connection, $organizationId);
            $workflows = data_get($workflowsResponse, 'workflows', []);

            $referenceId = null;
            foreach ((array) $workflows as $wf) {
                if (stripos((string) data_get($wf, 'workflow_name'), 'invoice is created') !== false) {
                    $referenceId = (string) data_get($wf, 'workflow_id', '');
                    break;
                }
            }

            if ($referenceId !== '') {
                $detail = $this->api->fetchWorkflow($connection, $referenceId, $organizationId);
                $refActions = data_get($detail, 'workflow.instant_actions', []);

                if (! empty($refActions)) {
                    // Mirror the first action's structure, replacing content with our webhook
                    $template = (array) $refActions[0];
                    $template['action_type'] = 'webhook';
                    $template['action_id']   = $webhookId;
                    $template['webhook_name'] = 'Payment Middleware – Invoice Notify';
                    $template['method']      = 'POST';
                    $template['url']         = $webhookUrl;
                    $template['body_type']   = 'application/json';
                    $template['raw_data']    = $rawBody;

                    return [$template];
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return [[
            'action_type'  => 'webhook',
            'action_id'    => $webhookId,
            'webhook_name' => 'Payment Middleware – Invoice Notify',
            'method'       => 'POST',
            'url'          => $webhookUrl,
            'entity'       => 'invoice',
            'body_type'    => 'application/json',
            'raw_data'     => $rawBody,
        ]];
    }
}
