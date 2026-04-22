<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Inbound Module - {{ config('app.name', 'Laravel') }}</title>
    <meta name="description" content="{{ $description ?? '' }}">
    <meta name="keywords" content="{{ $keywords ?? '' }}">
    <meta name="author" content="{{ $author ?? '' }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        body {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            background: linear-gradient(180deg, #f6f0ea 0%, #f8fbff 100%);
            color: #132238;
        }
        .shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }
        .panel {
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(19, 34, 56, 0.1);
            border-radius: 26px;
            padding: 24px;
            box-shadow: 0 18px 46px rgba(19, 34, 56, 0.08);
            margin-bottom: 18px;
        }
        .eyebrow {
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 12px;
            color: #6b7c93;
        }
        h1 { margin: 0 0 8px; font-size: clamp(2rem, 4vw, 3rem); }
        .copy { margin: 0 0 20px; color: #5f7089; max-width: 70ch; }
        .summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-bottom: 18px;
        }
        .summary-card, .field, .client-row {
            background: #fff;
            border: 1px solid rgba(19, 34, 56, 0.1);
            border-radius: 18px;
            padding: 14px 16px;
        }
        .summary-card span, .field span, .client-row span { display: block; color: #6b7c93; font-size: 0.95rem; }
        .summary-card strong, .client-row strong, .client-row code { display: block; margin-top: 6px; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .button {
            display: inline-block;
            text-decoration: none;
            border-radius: 999px;
            padding: 12px 18px;
            border: 0;
            cursor: pointer;
            font: inherit;
        }
        .button.primary { background: #132238; color: white; }
        .button.secondary { background: #e9eef5; color: #132238; }
        .notice {
            border-radius: 16px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }
        .notice.success { background: #e6f7ef; color: #15643b; }
        .notice.error { background: #fdeaea; color: #9a2f2f; }
        .form-grid {
            display: grid;
            gap: 14px;
        }
        .field input, .field select {
            width: 100%;
            margin-top: 8px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(19, 34, 56, 0.12);
            font: inherit;
        }
        .checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border: 1px solid rgba(19, 34, 56, 0.1);
            border-radius: 14px;
            background: #fff;
        }
        .client-list {
            display: grid;
            gap: 10px;
        }
        .client-row {
            text-decoration: none;
            color: inherit;
        }
        .empty { color: #6b7c93; }
    </style>
</head>
<body>
    {{ $slot }}
</body>
</html>
