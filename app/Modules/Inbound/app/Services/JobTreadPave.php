<?php

namespace Modules\Inbound\Services;

/**
 * Every JobTread (Pave) query shape SimpHub sends, kept in one place.
 *
 * Pave is a single POST endpoint: the body is {"query": {...}}, where each key is a field to
 * read, `{}` reads a scalar, and "$" carries arguments. Asking for a field that doesn't exist
 * fails the whole query, so document fields are split into groups: the client tries all of
 * them in one request and, if that's rejected, retries group by group and logs which group
 * failed. That keeps the integration working while a field name is wrong.
 *
 * VERIFY: field and enum names below come from the requirement write-up and third-party notes,
 * not from JobTread's schema. Confirm each one against app.jobtread.com/docs with HEP's grant key
 * (first-day tests), then fix it here.
 */
final class JobTreadPave
{
    /** Document type SimpHub bills. VERIFY (first-day test #6). */
    public const TYPE_CUSTOMER_INVOICE = 'customerInvoice';

    /** Document statuses. VERIFY. */
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED   = 'denied';

    /** Page size for list queries. */
    public const PAGE_SIZE = 100;

    /** Always readable; a failure here means the document can't be read at all. */
    public const DOCUMENT_CORE = [
        'id'     => [],
        'name'   => [],
        'number' => [],
        'type'   => [],
        'status' => [],
    ];

    /**
     * Optional groups, merged into one request when they all resolve.
     *
     * @var array<string, array<string, mixed>>
     */
    public const DOCUMENT_GROUPS = [
        // VERIFY: the field carrying what's still owed.
        'balance' => ['balance' => []],
        // VERIFY: invoice total before payments (used when balance is unavailable).
        'totals' => ['price' => [], 'tax' => []],
        'dates' => ['issueDate' => [], 'dueDate' => [], 'createdAt' => []],
        // VERIFY (first-day test #5): populated on an unsent invoice?
        'recipient' => ['toEmailAddress' => [], 'toName' => []],
        // VERIFY: recipient list, delivery status (accidental Send) and suppression flag.
        'recipients' => [
            'documentRecipients' => [
                'nodes' => [
                    'id'                  => [],
                    'emailAddress'        => [],
                    'emailDeliveryStatus' => [],
                    'user'                => [
                        'id'                        => [],
                        'name'                      => [],
                        'emailAddress'              => [],
                        'hasSuppressedEmailAddress' => [],
                    ],
                ],
            ],
        ],
        'job' => [
            'job' => [
                'id'       => [],
                'name'     => [],
                'number'   => [],
                'location' => [
                    'id'      => [],
                    'name'    => [],
                    'account' => ['id' => [], 'name' => []],
                ],
            ],
        ],
        // VERIFY: payments applied to this document, for duplicate detection on retry.
        'payments' => [
            'documentPayments' => [
                'nodes' => [
                    'id'      => [],
                    'amount'  => [],
                    'payment' => ['id' => [], 'externalId' => [], 'amount' => []],
                ],
            ],
        ],
    ];

    /** Organizations the grant can act for. VERIFY. */
    public static function currentGrant(): array
    {
        return [
            'currentGrant' => [
                'id'   => [],
                'user' => [
                    'id'          => [],
                    'name'        => [],
                    'memberships' => [
                        'nodes' => [
                            'organization' => ['id' => [], 'name' => []],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function organization(string $organizationId): array
    {
        return ['organization' => ['$' => ['id' => $organizationId], 'id' => [], 'name' => []]];
    }

    /** @param array<string, mixed> $fields */
    public static function document(string $documentId, array $fields): array
    {
        return [
            'document' => ['$' => ['id' => $documentId]] + $fields,
        ];
    }

    /**
     * One page of an organization's customer invoices in the given status. VERIFY the where
     * syntax (nested arrays) and the paging fields against the docs examples.
     */
    public static function invoicesByStatus(string $organizationId, string $status, ?string $page = null): array
    {
        $args = [
            'size'  => self::PAGE_SIZE,
            'where' => [
                'and' => [
                    [['type'], '=', self::TYPE_CUSTOMER_INVOICE],
                    [['status'], '=', $status],
                ],
            ],
        ];

        if ($page !== null && $page !== '') {
            $args['page'] = $page;
        }

        return [
            'organization' => [
                '$'         => ['id' => $organizationId],
                'id'        => [],
                'documents' => [
                    '$'        => $args,
                    'nextPage' => [],
                    'nodes'    => self::DOCUMENT_CORE,
                ],
            ],
        ];
    }

    /** A payment we created earlier, looked up by its externalId (our transaction ref). VERIFY. */
    public static function paymentsByExternalId(string $organizationId, string $externalId): array
    {
        return [
            'organization' => [
                '$'        => ['id' => $organizationId],
                'id'       => [],
                'payments' => [
                    '$'     => [
                        'size'  => 5,
                        'where' => [[['externalId'], '=', $externalId]],
                    ],
                    'nodes' => [
                        'id'               => [],
                        'amount'           => [],
                        'externalId'       => [],
                        'documentPayments' => [
                            'nodes' => ['id' => [], 'document' => ['id' => []]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    public static function createPayment(array $input): array
    {
        return ['createPayment' => ['$' => $input, 'createdPayment' => ['id' => []]]];
    }

    /** @param array<string, mixed> $input */
    public static function createDocumentPayment(array $input): array
    {
        return ['createDocumentPayment' => ['$' => $input, 'createdDocumentPayment' => ['id' => []]]];
    }

    /** @param list<string> $eventTypes */
    public static function createWebhook(string $organizationId, string $url, array $eventTypes): array
    {
        return [
            'createWebhook' => [
                '$'              => [
                    'organizationId' => $organizationId,
                    'url'            => $url,
                    'eventTypes'     => array_values($eventTypes),
                ],
                'createdWebhook' => ['id' => []],
            ],
        ];
    }

    public static function deleteWebhook(string $webhookId): array
    {
        return ['deleteWebhook' => ['$' => ['id' => $webhookId]]];
    }

    /** A signed, short-lived token that runs $query without the grant key (PDF links). VERIFY. */
    public static function signQuery(array $query): array
    {
        return ['signQuery' => ['$' => ['query' => $query]]];
    }

    /** What a document PDF link signs. VERIFY (first-day test #7). */
    public static function documentPdf(string $documentId): array
    {
        return ['document' => ['$' => ['id' => $documentId], 'pdf' => []]];
    }
}
