<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Models\PmsConnection;
use RuntimeException;

class ZohoWebhookSetupService
{
    public function __construct(
        private readonly ZohoApiClient $api,
    ) {}

    public function setup(PmsConnection $connection, string $pmsClientId): array
    {
        $organizationId = (string) data_get($connection->meta, 'default_organization_id', '');

        if ($organizationId === '') {
            throw new RuntimeException('Cannot auto-configure webhook: Zoho organization ID was not resolved during authentication.');
        }

        $webhookUrl = rtrim((string) config('services.zoho.webhook_callback_url'), '/');

        logger()->info('Zoho webhook setup: starting', [
            'pms_client_id' => $pmsClientId,
            'connection_id' => $connection->id,
            'organization_id' => $organizationId,
            'webhook_url' => $webhookUrl,
        ]);

        // Step 1: Create (or, on reconnect, update in place) the webhook.
        try {
            [$webhookId, $webhookAction] = $this->upsertWebhook($connection, $organizationId, [
                'webhook_name' => 'Payment Middleware – Invoice Notify',
                'description'  => 'Notifies the payment middleware when an invoice is created or updated.',
                'url'          => $webhookUrl,
                'method'       => 'POST',
                'body_type'    => 'application/json',
                'entity'       => 'invoice',
                'raw_data'     => $this->rawBody($pmsClientId, 'invoice.created'),
            ]);
        } catch (\Throwable $e) {
            logger()->error('Zoho webhook setup: create-webhook upsert failed', [
                'pms_client_id' => $pmsClientId,
                'connection_id' => $connection->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($webhookId === '') {
            logger()->error('Zoho webhook setup: create-webhook upsert returned no webhook id', [
                'pms_client_id' => $pmsClientId,
                'connection_id' => $connection->id,
            ]);

            throw new RuntimeException('Zoho did not return a webhook ID for the create-webhook.');
        }

        logger()->info('Zoho webhook setup: create-webhook registered', [
            'pms_client_id' => $pmsClientId,
            'connection_id' => $connection->id,
            'webhook_id' => $webhookId,
            'action' => $webhookAction,
        ]);

        // Step 2: Build the instant_action that references OUR webhook by its ID.
        // Fetch the reference workflow to learn the exact field names/structure
        // Zoho requires — then substitute only action_id with our webhook_id.
        // Inline body fields (url, method, body_type, raw_data) are intentionally
        // omitted so Zoho binds the existing webhook rather than creating a new one.
        $instantAction = $this->buildInstantAction($connection, $organizationId, $webhookId);

        // Step 3: Create (or update in place) the "created" workflow and bind our webhook to it.
        try {
            [$workflowId, $workflowAction] = $this->upsertWorkflow($connection, $organizationId, [
                'workflow_name'   => 'Payment Middleware – Invoice Created',
                'entity'          => 'invoice',
                'rule_type'       => 'add',
                'instant_actions' => [$instantAction],
            ]);
        } catch (\Throwable $e) {
            logger()->error('Zoho webhook setup: create-workflow upsert failed', [
                'pms_client_id' => $pmsClientId,
                'connection_id' => $connection->id,
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        logger()->info('Zoho webhook setup: create-workflow registered', [
            'pms_client_id' => $pmsClientId,
            'connection_id' => $connection->id,
            'webhook_id' => $webhookId,
            'workflow_id' => $workflowId,
            'action' => $workflowAction,
        ]);

        // Step 4: Second workflow, same webhook, fired on edit — resend-on-update relies
        // on this to tell create and update apart (Zoho's payload carries no such field
        // natively; the literal 'event' value in raw_data is what distinguishes them).
        // 'edit' as the rule_type is unverified against Zoho's live API — if Zoho rejects
        // it, this is caught so the primary create-webhook setup above still succeeds.
        $editWorkflowId = '';

        try {
            [$editWebhookId] = $this->upsertWebhook($connection, $organizationId, [
                'webhook_name' => 'Payment Middleware – Invoice Update Notify',
                'description'  => 'Notifies the payment middleware when an invoice is updated.',
                'url'          => $webhookUrl,
                'method'       => 'POST',
                'body_type'    => 'application/json',
                'entity'       => 'invoice',
                'raw_data'     => $this->rawBody($pmsClientId, 'invoice.updated'),
            ]);

            if ($editWebhookId !== '') {
                $editInstantAction = $this->buildInstantAction($connection, $organizationId, $editWebhookId);

                [$editWorkflowId] = $this->upsertWorkflow($connection, $organizationId, [
                    'workflow_name'   => 'Payment Middleware – Invoice Updated',
                    'entity'          => 'invoice',
                    'rule_type'       => 'edit',
                    'instant_actions' => [$editInstantAction],
                ]);

                logger()->info('Zoho webhook setup: update-webhook/workflow registered', [
                    'pms_client_id' => $pmsClientId,
                    'connection_id' => $connection->id,
                    'edit_webhook_id' => $editWebhookId,
                    'edit_workflow_id' => $editWorkflowId,
                ]);
            }
        } catch (\Throwable $e) {
            logger()->warning('Zoho: failed to register the invoice-updated webhook/workflow — resend-on-update will not fire for this connection', [
                'pms_client_id' => $pmsClientId,
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);
        }

        logger()->info('Zoho webhook setup: finished', [
            'pms_client_id' => $pmsClientId,
            'connection_id' => $connection->id,
            'webhook_id' => $webhookId,
            'workflow_id' => $workflowId,
            'edit_workflow_id' => $editWorkflowId,
        ]);

        return [
            'webhook_id'        => $webhookId,
            'workflow_id'       => $workflowId,
            'edit_workflow_id'  => $editWorkflowId,
        ];
    }

    /**
     * Creates a webhook by name, or — if one with that exact name already exists
     * in the org (e.g. a prior connect/reconnect for this client) — updates it in
     * place instead. Zoho rejects a second create with the same name (code 107051),
     * so without this, every reconnect after the first would fail auto-setup.
     *
     * @return array{0: string, 1: 'created'|'updated'} [webhook_id, action]
     */
    private function upsertWebhook(PmsConnection $connection, string $organizationId, array $payload): array
    {
        $name = (string) $payload['webhook_name'];
        $existingId = $this->findExistingByName(
            (array) data_get($this->api->fetchWebhooks($connection, $organizationId), 'webhooks', []),
            'webhook_name',
            'webhook_id',
            $name,
        );

        if ($existingId !== null) {
            $this->api->updateWebhook($connection, $existingId, $organizationId, $payload);

            return [$existingId, 'updated'];
        }

        $response = $this->api->createWebhook($connection, $organizationId, $payload);
        $id = (string) (data_get($response, 'webhook.webhook_id') ?? data_get($response, 'webhook_id') ?? '');

        return [$id, 'created'];
    }

    /** @return array{0: string, 1: 'created'|'updated'} [workflow_id, action] */
    private function upsertWorkflow(PmsConnection $connection, string $organizationId, array $payload): array
    {
        $name = (string) $payload['workflow_name'];
        $existingId = $this->findExistingByName(
            (array) data_get($this->api->fetchWorkflows($connection, $organizationId), 'workflows', []),
            'workflow_name',
            'workflow_id',
            $name,
        );

        if ($existingId !== null) {
            $this->api->updateWorkflow($connection, $existingId, $organizationId, $payload);

            return [$existingId, 'updated'];
        }

        $response = $this->api->createWorkflow($connection, $organizationId, $payload);
        $id = (string) (data_get($response, 'workflow.workflow_id') ?? data_get($response, 'workflow_id') ?? '');

        return [$id, 'created'];
    }

    private function findExistingByName(array $items, string $nameKey, string $idKey, string $name): ?string
    {
        foreach ($items as $item) {
            if ((string) data_get($item, $nameKey) === $name) {
                return (string) data_get($item, $idKey);
            }
        }

        return null;
    }

    private function rawBody(string $pmsClientId, string $event): string
    {
        return json_encode([
            'pms_client_id' => $pmsClientId,
            'invoice_id'    => '${INVOICE.INVOICE_ID}',
            'total_amount'  => '${INVOICE.INVOICE_TOTAL}',
            'status'        => '${INVOICE.STATUS}',
            'source'        => 'zoho',
            'event'         => $event,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fetch the existing "When an invoice is created" workflow, find its webhook
     * instant_action, copy only the structural fields (action_type + any metadata
     * Zoho requires), then replace action_id with our newly created webhook's ID.
     *
     * Inline fields (url, method, body_type, raw_data, webhook_name, description)
     * are stripped — they would cause Zoho to create a brand-new webhook instead
     * of binding the one we already created.
     */
    private function buildInstantAction(
        PmsConnection $connection,
        string $organizationId,
        string $webhookId,
    ): array {
        // Fields that describe webhook content — keeping them causes Zoho to create
        // a new webhook inline instead of referencing the existing one by action_id.
        $inlineFields = [
            'url', 'method', 'body_type', 'raw_data',
            'webhook_name', 'description', 'headers',
            'entity_parameters', 'query_parameters', 'form_data',
            'additional_parameters', 'secret',
            'user_defined_format_name', 'user_defined_format_value',
            'is_new_response_format',
        ];

        /*try {
            $listResponse = $this->api->fetchWorkflows($connection, $organizationId);

            $referenceId = '';
            foreach ((array) data_get($listResponse, 'workflows', []) as $wf) {
                if (stripos((string) data_get($wf, 'workflow_name'), 'invoice is created') !== false) {
                    $referenceId = (string) data_get($wf, 'workflow_id', '');
                    break;
                }
            }

            if ($referenceId !== '') {
                $detail     = $this->api->fetchWorkflow($connection, $referenceId, $organizationId);
                $refActions = (array) data_get($detail, 'workflow.instant_actions', []);

                // Find the webhook-type action in the reference workflow.
                $refAction = null;
                foreach ($refActions as $a) {
                    if (strtolower((string) data_get($a, 'action_type', '')) === 'webhook') {
                        $refAction = (array) $a;
                        break;
                    }
                }

                // Fall back to first action if none is explicitly typed as webhook.
                if ($refAction === null && ! empty($refActions)) {
                    $refAction = (array) $refActions[0];
                }

                if ($refAction !== null) {
                    // Strip inline body fields — keep only structural metadata.
                    foreach ($inlineFields as $f) {
                        unset($refAction[$f]);
                    }

                    // Bind our webhook by replacing the action_id.
                    $refAction['action_id']   = $webhookId;
                    $refAction['action_type'] = $refAction['action_type'] ?? 'webhook';

                    return $refAction;
                }
            }
        } catch (\Throwable) {
            // Non-fatal — fall through to minimal default below.
        }
*/
        return [
            'action_type' => 'webhook',
            'action_id'   => $webhookId,
        ];
    }
}
