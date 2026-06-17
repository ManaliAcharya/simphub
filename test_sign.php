<?php

$clientApiKey  = 'YOUR_CLIENT_API_KEY_HERE';
$signingSecret = 'bss_Zuq6mXeJeeux5Tirwe6Vw5WfNFCYaXcfO5Inz1SborXuXtLzOUOB';
$postingUrl    = 'https://paymentmiddleware.myreporthub.dev/booksync/post/tok_LiuUTUxWOE6N5KERJBX29zMFOzNpTgMM';

// Build body with json_encode — this is the exact string that will be sent
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

$timestamp     = time();
$signedPayload = $timestamp . '.' . $body;
$signature     = 'sha256=' . hash_hmac('sha256', $signedPayload, $signingSecret);

echo "Timestamp : $timestamp\n";
echo "Signature : $signature\n";
echo "Body      : $body\n\n";

// Send the request using the same $body string that was signed
$ch = curl_init($postingUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer '   . $clientApiKey,
        'Content-Type: application/json',
        'X-BookSync-Timestamp: '   . $timestamp,
        'X-BookSync-Signature: '   . $signature,
    ],
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $status\n";
echo $response . "\n";
