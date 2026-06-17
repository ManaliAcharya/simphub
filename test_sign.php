<?php

$signingSecret = 'bss_Zuq6mXeJeeux5Tirwe6Vw5WfNFCYaXcfO5Inz1SborXuXtLzOUOB';
$postingUrl    = 'https://paymentmiddleware.myreporthub.dev/booksync/post/tok_LiuUTUxWOE6N5KERJBX29zMFOzNpTgMM';
$timestamp     = time();

$body = json_encode([
    'batch_date'   => '2026-06-17',
    'transactions' => [[
        'reference'      => 'TEST-TXN-001',
        'customer_name'  => 'Walk-in Customer',
        'amount'         => 47.50,
        'payment_method' => 'Cash',
        'memo'           => 'Test posting',
    ]],
]);

$signedPayload = $timestamp . '.' . $body;
$signature     = 'sha256=' . hash_hmac('sha256', $signedPayload, $signingSecret);

echo "Posting URL : $postingUrl\n";
echo "Timestamp   : $timestamp\n";
echo "Signature   : $signature\n";
echo "\nBody:\n$body\n";
