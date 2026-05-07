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

        $rawBody = json_encode([
            'pms_client_id' => $pmsClientId,
            'invoice_id'    => '${INVOICE.INVOICE_ID}',
            'total_amount'  => '${INVOICE.INVOICE_TOTAL}',
            'status'        => '${INVOICE.STATUS}',
            'source'        => 'zoho',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Step 1: Create the webhook.
        $webhookResponse = $this->api->createWebhook($connection, $organizationId, [
            'webhook_name' => 'Payment Middleware – Invoice Notify',
            'description'  => 'Notifies the payment middleware when an invoice is created.',
            'url'          => $webhookUrl,
            'method'       => 'POST',
            'body_type'    => 'application/json',
            'entity'       => 'invoice',
            'raw_data'     => $rawBody,
        ]);

        $webhookId = (string) (
            data_get($webhookResponse, 'webhook.webhook_id')
            ?? data_get($webhookResponse, 'webhook_id')
            ?? ''
        );

        if ($webhookId === '') {
            throw new RuntimeException('Zoho did not return a webhook ID. Response: ' . json_encode($webhookResponse));
        }

        // Step 2: Build the instant_action that references OUR webhook by its ID.
        // Fetch the reference workflow to learn the exact field names/structure
        // Zoho requires — then substitute only action_id with our webhook_id.
        // Inline body fields (url, method, body_type, raw_data) are intentionally
        // omitted so Zoho binds the existing webhook rather than creating a new one.
        $instantAction = $this->buildInstantAction($connection, $organizationId, $webhookId);

        // Step 3: Create the workflow and bind our webhook to it.
        $workflowResponse = $this->api->createWorkflow($connection, $organizationId, [
            'workflow_name'   => 'Payment Middleware – Invoice Created',
            'entity'          => 'invoice',
            'rule_type'       => 'add',
            'instant_actions' => [$instantAction],
        ]);

        $workflowId = (string) (
            data_get($workflowResponse, 'workflow.workflow_id')
            ?? data_get($workflowResponse, 'workflow_id')
            ?? ''
        );

        return [
            'webhook_id'  => $webhookId,
            'workflow_id' => $workflowId,
        ];
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
