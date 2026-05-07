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
     * Creates a Zoho Books workflow with an inline webhook action.
     * The webhook is embedded directly in instant_actions so Zoho creates and
     * binds it in one step — no separate webhook creation call needed.
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
            'invoice_id'    => '${INVOICE.INVOICE_ID}',
            'total_amount'  => '${INVOICE.INVOICE_TOTAL}',
            'status'        => '${INVOICE.STATUS}',
            'source'        => 'zoho',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Build the webhook action using the reference workflow's structure as a base.
        $webhookAction = $this->buildWebhookAction($connection, $organizationId, $webhookUrl, $rawBody);

        $workflowResponse = $this->api->createWorkflow($connection, $organizationId, [
            'workflow_name'   => 'Payment Middleware – Invoice Created',
            'entity'          => 'invoice',
            'rule_type'       => 'add',
            'instant_actions' => [$webhookAction],
        ]);

        $workflowId = (string) (
            data_get($workflowResponse, 'workflow.workflow_id')
            ?? data_get($workflowResponse, 'workflow_id')
            ?? ''
        );

        // Zoho assigns an action_id to the inline webhook — extract it from the response.
        $webhookId = (string) (
            data_get($workflowResponse, 'workflow.instant_actions.0.action_id')
            ?? data_get($workflowResponse, 'workflow.instant_actions.0.webhook_id')
            ?? ''
        );

        return [
            'webhook_id'  => $webhookId,
            'workflow_id' => $workflowId,
        ];
    }

    /**
     * Fetches the existing "When an invoice is created" workflow to obtain the
     * exact instant_actions shape Zoho accepts, then merges our webhook details in.
     * Falls back to a plain default if the reference workflow cannot be fetched.
     */
    private function buildWebhookAction(
        PmsConnection $connection,
        string $organizationId,
        string $webhookUrl,
        string $rawBody,
    ): array {
        $base = [];

        try {
            $list = $this->api->fetchWorkflows($connection, $organizationId);

            $referenceId = '';
            foreach ((array) data_get($list, 'workflows', []) as $wf) {
                if (stripos((string) data_get($wf, 'workflow_name'), 'invoice is created') !== false) {
                    $referenceId = (string) data_get($wf, 'workflow_id', '');
                    break;
                }
            }

            if ($referenceId !== '') {
                $detail     = $this->api->fetchWorkflow($connection, $referenceId, $organizationId);
                $refActions = (array) data_get($detail, 'workflow.instant_actions', []);

                if (! empty($refActions)) {
                    // Use the reference action as a base — keeps any fields Zoho requires
                    // that are not documented. Remove action_id so Zoho assigns a new one.
                    $base = (array) $refActions[0];
                    unset($base['action_id'], $base['webhook_id']);
                }
            }
        } catch (\Throwable) {
            // Non-fatal — fall through to plain defaults
        }

        return array_merge($base, [
            'action_type'  => 'webhook',
            'webhook_name' => 'Payment Middleware – Invoice Notify',
            'method'       => 'POST',
            'url'          => $webhookUrl,
            'body_type'    => 'application/json',
            'raw_data'     => $rawBody,
        ]);
    }
}
