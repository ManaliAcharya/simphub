<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Jobs\SyncJobTreadConnectionJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Inbound\Services\JobTreadDocument;
use Modules\Inbound\Services\JobTreadInvoiceIngestionService;
use Modules\Inbound\Services\JobTreadLiveBalanceService;
use Modules\Inbound\Services\JobTreadPaymentSyncService;
use Modules\Inbound\Services\JobTreadSyncService;
use Modules\Inbound\Services\JobTreadWebhookService;
use Modules\Payment\Mail\PaymentLinkMail;
use Modules\Routing\Models\RoutingRule;
use Tests\TestCase;

/**
 * JobTread: an invoice reaching "pending" emails its payment link once; Make Changes + Push
 * to Pending emails it again; payments are written back with createPayment +
 * createDocumentPayment (notify: false). The Pave API is faked by answering on the query's
 * top-level key.
 */
class JobTreadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.jobtread.test/pave';

    /** The JobTread invoice as the fake API currently returns it. */
    private array $doc = [];

    /** Top-level keys the fake rejects, as Pave would for a field not in the schema. */
    private array $rejectFields = [];

    /** @var list<array> query bodies received */
    private array $queries = [];

    private array $pendingList = [];

    private array $paymentsByExternalId = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.jobtread.api_url', self::API);
        config()->set('services.jobtread.min_request_interval_ms', 0);
        config()->set('services.jobtread.native_pdf', false);
        config()->set('services.jobtread.webhook_callback_url', 'https://simphub.test/api/v1/inbound/webhooks/jobtread');
        // Full behaviour; the live-account safety switches have their own tests below.
        config()->set('services.jobtread.webhooks_enabled', true);
        config()->set('services.jobtread.payment_writeback', true);
        config()->set('services.jobtread.only_recipients', []);
        config()->set('services.jobtread.only_account_ids', []);

        $this->doc = [
            'id'             => 'doc-1',
            'name'           => 'Invoice',
            'number'         => '1001',
            'type'           => 'customerInvoice',
            'status'         => 'pending',
            'balance'        => 250.00,
            'price'          => 250.00,
            'tax'            => 0,
            'issueDate'      => '2026-11-20',
            'dueDate'        => '2026-12-20',
            'toEmailAddress' => 'customer@example.com',
            'toName'         => 'Jane Homeowner',
            'documentRecipients' => ['nodes' => []],
            'documentPayments'   => ['nodes' => []],
        ];

        Http::fake([self::API => fn (HttpRequest $request) => $this->answer($request)]);
    }

    public function test_an_invoice_pushed_to_pending_is_emailed_once(): void
    {
        $client = $this->connectedClient();

        $first = $this->ingest($client);

        $this->assertSame(1, $first['emails_sent']);
        $this->assertSame(25000, $first['invoice']->amount_cents);
        $this->assertSame('PENDING', $first['invoice']->status);
        $this->assertSame(['customer@example.com'], $first['invoice']->recipient_emails);
        $this->assertSame('Jane Homeowner', $first['invoice']->customer['name']);

        // Read again, nothing changed (e.g. the poll after the webhook): no second email.
        $this->assertSame(0, $this->ingest($client)['emails_sent']);

        // A payment applied in JobTread changes the balance, not the invoice: no email.
        $this->doc['balance'] = 100.00;
        $again = $this->ingest($client);
        $this->assertSame(0, $again['emails_sent']);
        $this->assertSame(10000, $again['invoice']->amount_cents, 'the link charges what is still owed');

        Mail::assertSent(PaymentLinkMail::class, 1);
    }

    public function test_make_changes_then_push_to_pending_emails_the_updated_invoice(): void
    {
        $client  = $this->connectedClient();
        $session = $this->ingest($client)['payment_session'];

        // Make Changes: back to Draft. Not payable, no email.
        $this->doc['status'] = 'draft';
        $draft = $this->ingest($client);
        $this->assertSame(0, $draft['emails_sent']);
        $this->assertSame('DRAFT', $draft['invoice']->status);

        // Pushed to pending again with a new amount: emailed again, same link.
        $this->doc['status']  = 'pending';
        $this->doc['price']   = 300.00;
        $this->doc['balance'] = 300.00;
        $pushed = $this->ingest($client);

        $this->assertSame(1, $pushed['emails_sent']);
        $this->assertSame(30000, $pushed['invoice']->amount_cents);
        $this->assertSame($session->id, $pushed['payment_session']->id);
        Mail::assertSent(PaymentLinkMail::class, 2);
    }

    public function test_an_edit_and_push_between_two_reads_is_still_detected(): void
    {
        $client = $this->connectedClient();
        $this->ingest($client);

        // Draft and back to pending happened between polls: status reads "pending" both times.
        $this->doc['price']   = 275.00;
        $this->doc['balance'] = 275.00;

        $this->assertSame(1, $this->ingest($client)['emails_sent']);
        $this->assertSame(0, $this->ingest($client)['emails_sent']);
        Mail::assertSent(PaymentLinkMail::class, 2);
    }

    public function test_invoices_pending_before_connecting_are_not_emailed_unless_pushed_again(): void
    {
        $client = $this->connectedClient(baseline: ['doc-1' => JobTreadDocument::fingerprint($this->doc)]);

        $skipped = $this->ingest($client);
        $this->assertNull($skipped['invoice']);
        $this->assertSame('pending_before_connect', $skipped['skipped']);

        $this->doc['price']   = 260.00;
        $this->doc['balance'] = 260.00;
        $this->assertSame(1, $this->ingest($client)['emails_sent']);
    }

    public function test_only_pending_customer_invoices_are_recorded(): void
    {
        $client = $this->connectedClient();

        $this->doc['type'] = 'customerOrder';
        $this->assertSame('not_customer_invoice', $this->ingest($client)['skipped']);

        $this->doc['type']   = 'customerInvoice';
        $this->doc['status'] = 'draft';
        $this->assertSame('not_pending', $this->ingest($client)['skipped']);

        $this->assertSame(0, Invoice::query()->count());
        Mail::assertNothingSent();
    }

    public function test_a_denied_invoice_closes_its_link(): void
    {
        $client  = $this->connectedClient();
        $session = $this->ingest($client)['payment_session'];

        $this->doc['status'] = 'denied';
        $this->ingest($client);

        $this->assertSame('voided', $session->fresh()->link_status);
    }

    public function test_one_wrong_field_name_does_not_stop_invoices(): void
    {
        $client             = $this->connectedClient();
        $this->rejectFields = ['documentRecipients'];

        $result = $this->ingest($client);

        // Read group by group; the recipients group is left out, the "to" address still works.
        $this->assertSame(1, $result['emails_sent']);
        $this->assertSame(['customer@example.com'], $result['invoice']->recipient_emails);
    }

    public function test_sync_reads_pending_invoices_and_open_ones(): void
    {
        $client            = $this->connectedClient();
        $this->pendingList = [['id' => 'doc-1', 'type' => 'customerInvoice', 'status' => 'pending']];

        $counts = app(JobTreadSyncService::class)->sync(JobTreadConnection::forClient($client->pms_client_id));

        $this->assertSame(['checked' => 1, 'emailed' => 1, 'failed' => 0], $counts);
    }

    public function test_sync_records_the_baseline_first_and_emails_nothing(): void
    {
        $client            = $this->connectedClient(baseline: null);
        $this->pendingList = [['id' => 'doc-1', 'type' => 'customerInvoice', 'status' => 'pending']];
        $connection        = JobTreadConnection::forClient($client->pms_client_id);

        $this->assertSame(0, app(JobTreadSyncService::class)->sync($connection)['emailed']);
        $this->assertSame(['doc-1' => JobTreadDocument::fingerprint($this->doc)], $connection->fresh()->meta['baseline']);
        $this->assertSame(0, app(JobTreadSyncService::class)->sync($connection->fresh())['emailed']);
        Mail::assertNothingSent();
    }

    public function test_webhook_needs_the_client_token_and_only_queues_a_sync(): void
    {
        Queue::fake();
        $client     = $this->connectedClient();
        $connection = JobTreadConnection::forClient($client->pms_client_id);
        $url        = '/api/v1/inbound/webhooks/jobtread?pms_client_id='.$client->pms_client_id;

        $this->postJson($url.'&token=wrong', ['documentId' => 'doc-1'])->assertNotFound();
        $this->postJson($url, ['documentId' => 'doc-1'])->assertNotFound();
        Queue::assertNothingPushed();

        $this->postJson($url.'&token='.$connection->webhook_secret, ['type' => 'documentUpdated', 'data' => ['document' => ['id' => 'doc-1']]])
            ->assertStatus(202);

        Queue::assertPushed(SyncJobTreadConnectionJob::class, fn ($job) => $job->pmsClientId === $client->pms_client_id
            && $job->documentIds === ['doc-1']);
    }

    public function test_document_ids_are_found_in_unknown_payload_shapes(): void
    {
        $this->assertSame(['a'], JobTreadWebhookService::documentIds(['documentId' => 'a']));
        $this->assertSame(['b'], JobTreadWebhookService::documentIds(['event' => ['data' => ['document' => ['id' => 'b']]]]));
        $this->assertSame([], JobTreadWebhookService::documentIds(['id' => 'evt-1', 'type' => 'documentUpdated']));
    }

    public function test_connecting_with_webhooks_switched_off_only_reads(): void
    {
        config()->set('services.jobtread.webhooks_enabled', false);
        Mail::fake();
        Client::query()->forceCreate(['id' => (string) Str::uuid(), 'pms_client_id' => $id = (string) Str::uuid(), 'client_name' => 'HEP', 'client_pms' => 'JOBTREAD']);

        app(\Modules\Inbound\Services\JobTreadConnectionService::class)->connect('grant-key', $id);

        $this->assertNull($this->mutation('createWebhook'));
        $this->assertSame([], array_values(array_diff(
            array_map(fn ($q) => array_key_first($q), $this->queries),
            ['currentGrant', 'organization', 'document'],
        )), 'Only read queries are sent.');
        $this->assertSame('org-1', JobTreadConnection::forClient($id)->organizationId());
    }

    public function test_with_write_back_switched_off_nothing_is_written_to_jobtread(): void
    {
        config()->set('services.jobtread.payment_writeback', false);
        $client = $this->connectedClient();
        [$transaction, $invoice] = $this->paidInvoice($client);
        $statusBefore = $invoice->fresh()->pms_sync_status;

        $this->assertFalse(app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice));

        $this->assertNull($this->mutation('createPayment'));
        $this->assertNull($this->mutation('createDocumentPayment'));
        $this->assertSame($statusBefore, $invoice->fresh()->pms_sync_status, 'Not marked as recorded in JobTread.');
        $this->assertTrue(\Modules\Audit\Models\AuditLog::query()->where('event_type', 'PMS_PAYMENT_RECORD_SKIPPED')->exists());
    }

    public function test_the_test_filter_only_processes_matching_invoices(): void
    {
        $client = $this->connectedClient();

        config()->set('services.jobtread.only_recipients', ['me@example.com']);
        $this->assertSame('outside_test_filter', $this->ingest($client)['skipped'] ?? null);
        $this->assertFalse(Invoice::query()->where('external_invoice_id', 'doc-1')->exists(), 'Other customers are not stored.');
        Mail::assertNothingSent();

        $this->doc['toEmailAddress'] = 'Me@Example.com';
        $this->assertSame(1, $this->ingest($client)['emails_sent']);

        // Or by JobTread customer account.
        config()->set('services.jobtread.only_recipients', []);
        config()->set('services.jobtread.only_account_ids', ['acct-me']);
        $this->doc['job'] = ['id' => 'job-1', 'location' => ['id' => 'loc-1', 'account' => ['id' => 'acct-other']]];
        $this->assertSame('outside_test_filter', $this->ingest($client)['skipped'] ?? null);
        $this->doc['job']['location']['account']['id'] = 'acct-me';
        $this->assertNotSame('outside_test_filter', $this->ingest($client)['skipped'] ?? null);
    }

    /** Responses below are what the live API returned on 2026-11-27. */
    public function test_a_revoked_key_is_recognised_from_jobtreads_real_response(): void
    {
        $revoked = new \Modules\Inbound\Services\JobTreadApiException('JobTread API error 400: Supplied key is invalid or expired', 400);
        $badField = new \Modules\Inbound\Services\JobTreadApiException('JobTread API error 400: The field "emailAddress" does not exist at "document"."documentRecipients"."nodes"', 400);

        $this->assertTrue($revoked->isAuthFailure());
        $this->assertFalse($badField->isAuthFailure(), 'A wrong field name must not look like a revoked key.');
    }

    public function test_the_payment_lookup_sends_a_single_where_condition(): void
    {
        $query = \Modules\Inbound\Services\JobTreadPave::paymentsByExternalId('org-1', 'simphub-gw-txn-1');

        $this->assertSame([['externalId'], '=', 'simphub-gw-txn-1'], $query['organization']['payments']['$']['where']);
    }

    public function test_recipients_skip_suppressed_users(): void
    {
        $document = [
            'documentRecipients' => ['nodes' => [
                ['user' => ['emailAddress' => 'Bounced@Example.com', 'hasSuppressedEmailAddress' => true]],
                ['user' => ['emailAddress' => 'Owner@Example.com', 'hasSuppressedEmailAddress' => false]],
            ]],
        ];

        $this->assertSame(['owner@example.com'], JobTreadDocument::recipientEmails($document));
        $this->assertSame(['to@example.com'], JobTreadDocument::recipientEmails($document + ['toEmailAddress' => 'to@example.com']));
    }

    public function test_payment_is_written_back_without_notifying_and_not_twice(): void
    {
        $client = $this->connectedClient();
        [$transaction, $invoice] = $this->paidInvoice($client);

        $this->assertTrue(app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice));

        $createPayment = $this->mutation('createPayment');
        $this->assertSame('org-1', $createPayment['organizationId']);
        $this->assertEquals(250.0, $createPayment['amount']);
        $this->assertSame('simphub-gw-txn-1', $createPayment['externalId']);
        $this->assertSame('credit', $createPayment['type']);
        $this->assertFalse($createPayment['notify']);

        $apply = $this->mutation('createDocumentPayment');
        $this->assertSame(['documentId' => 'doc-1', 'paymentId' => 'pay-1', 'amount' => 250.0, 'notify' => false], $apply);
        $this->assertSame('SYNCED', $invoice->fresh()->pms_sync_status);

        // A retry sees our payment on the invoice and writes nothing.
        $this->queries = [];
        $this->assertTrue(app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice));
        $this->assertNull($this->mutation('createPayment'));
        $this->assertNull($this->mutation('createDocumentPayment'));
    }

    public function test_a_payment_created_but_not_applied_is_only_applied_on_retry(): void
    {
        $client = $this->connectedClient();
        [$transaction, $invoice] = $this->paidInvoice($client);
        $this->paymentsByExternalId = [['id' => 'pay-earlier', 'externalId' => 'simphub-gw-txn-1', 'amount' => 250]];

        $this->assertTrue(app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice));

        $this->assertNull($this->mutation('createPayment'));
        $this->assertSame('pay-earlier', $this->mutation('createDocumentPayment')['paymentId']);
    }

    public function test_the_card_fee_is_not_written_to_the_invoice(): void
    {
        $client = $this->connectedClient();
        [$transaction, $invoice] = $this->paidInvoice($client, chargedCents: 25875, feeCents: 875);

        app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice);

        $this->assertEquals(250.0, $this->mutation('createPayment')['amount']);
    }

    public function test_an_unconfirmed_payment_asks_the_merchant_to_record_it_once(): void
    {
        $client = $this->connectedClient();
        $client->forceFill(['payment_link_admin_email' => 'office@hep.test'])->save();
        [$transaction, $invoice] = $this->paidInvoice($client);
        $this->rejectFields = ['createDocumentPayment'];

        $this->assertFalse(app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice));
        $this->assertFalse(app(JobTreadPaymentSyncService::class)->sync($transaction, $invoice));

        $this->assertSame('FAILED', $invoice->fresh()->pms_sync_status);
        $this->assertSame(1, \Modules\Audit\Models\AuditLog::query()
            ->where('event_type', JobTreadPaymentSyncService::ALERT_SENT_EVENT)->count());
    }

    public function test_a_draft_invoice_cannot_be_paid_and_a_paid_one_closes(): void
    {
        $client  = $this->connectedClient();
        $result  = $this->ingest($client);
        $live    = app(JobTreadLiveBalanceService::class);

        $this->doc['status'] = 'draft';
        $this->assertSame(JobTreadLiveBalanceService::EDITING, $live->sync($result['invoice'], $result['payment_session']));

        $this->doc['status']  = 'pending';
        $this->doc['balance'] = 120.00;
        $this->assertSame(JobTreadLiveBalanceService::PAYABLE, $live->sync($result['invoice']->fresh(), $result['payment_session']));
        $this->assertSame(12000, $result['invoice']->fresh()->amount_cents);

        $this->doc['balance'] = 0;
        $this->assertSame(JobTreadLiveBalanceService::CLOSED, $live->sync($result['invoice']->fresh(), $result['payment_session']));
        $this->assertSame('paid', $result['payment_session']->fresh()->link_status);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ingest(Client $client): array
    {
        return app(JobTreadInvoiceIngestionService::class)->ingest('doc-1', [
            'pms_client_id' => $client->pms_client_id,
            'event_name'    => 'poll',
        ]);
    }

    private function connectedClient(?array $baseline = []): Client
    {
        Mail::fake();

        $client = Client::query()->forceCreate([
            'id'                    => (string) Str::uuid(),
            'pms_client_id'         => (string) Str::uuid(),
            'client_name'           => 'House Electric & Plumbing',
            'client_pms'            => 'JOBTREAD',
            'auto_resend_on_change' => true,
            'call_api_to_pms'       => true,
        ]);

        $meta = ['organization_id' => 'org-1', 'organization_name' => 'HEP'];

        if ($baseline !== null) {
            $meta['baseline'] = $baseline;
        }

        JobTreadConnection::query()->create([
            'provider'       => 'jobtread',
            'pms_client_id'  => $client->pms_client_id,
            'access_token'   => 'grant-key',
            'webhook_secret' => Str::random(48),
            'meta'           => $meta,
        ]);

        return $client;
    }

    /** @return array{0: Transaction, 1: Invoice} */
    private function paidInvoice(Client $client, int $chargedCents = 25000, int $feeCents = 0): array
    {
        $result  = $this->ingest($client);
        $invoice = $result['invoice'];
        $session = $result['payment_session'];

        $rule = RoutingRule::query()->firstOrCreate(['mid' => 'MID-TEST'], [
            'merchant_id' => 'default', 'gateway' => 'fluidpay', 'payment_method' => 'ANY', 'fund_type' => 'ANY',
            'mid_credentials' => ['environment' => 'sandbox'],
        ]);

        $transaction = Transaction::query()->create([
            'payment_session_id' => $session->id,
            'routing_rule_id'    => $rule->id,
            'invoice_id'         => $invoice->id,
            'gateway'            => 'fluidpay',
            'gateway_txn_id'     => 'gw-txn-1',
            'mid'                => 'MID-TEST',
            'gateway_token'      => '',
            'status'             => 'CAPTURED',
            'transaction_type'   => 'debit',
            'fund_type'          => 'OPERATING',
            'amount_cents'       => $chargedCents,
            'fee_cents'          => $feeCents,
            'currency'           => 'USD',
        ]);

        return [$transaction, $invoice];
    }

    private function mutation(string $key): ?array
    {
        foreach (array_reverse($this->queries) as $query) {
            if (isset($query[$key])) {
                return $query[$key]['$'];
            }
        }

        return null;
    }

    /** A tiny Pave: answers on the query's top-level key. */
    private function answer(HttpRequest $request)
    {
        $query = (array) ($request->data()['query'] ?? []);
        unset($query['$']);
        $this->queries[] = $query;

        $key = array_key_first($query);

        foreach ($this->rejectFields as $field) {
            if ($key === $field || ($key === 'document' && array_key_exists($field, $query['document']))) {
                return Http::response(['message' => "Field '{$field}' does not exist"], 400);
            }
        }

        return match ($key) {
            'document' => Http::response(['document' => $this->doc]),
            'currentGrant' => Http::response(['currentGrant' => ['id' => 'grant-1', 'user' => ['id' => 'u-1', 'name' => 'Owner',
                'memberships' => ['nodes' => [['organization' => ['id' => 'org-1', 'name' => 'HEP']]]]]]]),
            'organization' => isset($query['organization']['payments'])
                ? Http::response(['organization' => ['id' => 'org-1', 'payments' => ['nodes' => $this->paymentsByExternalId]]])
                : Http::response(['organization' => ['id' => 'org-1', 'documents' => ['nodes' => $this->pendingList, 'nextPage' => null]]]),
            'createPayment' => Http::response(['createPayment' => ['createdPayment' => ['id' => 'pay-1']]]),
            'createDocumentPayment' => $this->applyPayment($query['createDocumentPayment']['$']),
            default => Http::response(['message' => 'unexpected query '.$key], 400),
        };
    }

    private function applyPayment(array $input)
    {
        $this->doc['balance'] = max(0, $this->doc['balance'] - $input['amount']);
        $this->doc['documentPayments']['nodes'][] = [
            'id'      => 'dp-1',
            'amount'  => $input['amount'],
            'payment' => ['id' => $input['paymentId'], 'externalId' => 'simphub-gw-txn-1'],
        ];

        return Http::response(['createDocumentPayment' => ['createdDocumentPayment' => ['id' => 'dp-1']]]);
    }
}
