<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Payment Module - {{ config('app.name', 'Laravel') }}</title>
    <meta name="description" content="{{ $description ?? '' }}">
    <meta name="keywords" content="{{ $keywords ?? '' }}">
    <meta name="author" content="{{ $author ?? '' }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --ink: #10213a;
            --muted: #60708a;
            --card: rgba(255,255,255,0.92);
            --accent: #ef8354;
            --accent-dark: #d96b3b;
            --line: rgba(16, 33, 58, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(239, 131, 84, 0.28), transparent 34%),
                radial-gradient(circle at top right, rgba(16, 33, 58, 0.16), transparent 30%),
                linear-gradient(180deg, #f8efe6 0%, #fbf8f4 54%, #f3f6fb 100%);
            min-height: 100vh;
        }
        .shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }
        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: 0 18px 48px rgba(16, 33, 58, 0.08);
            backdrop-filter: blur(10px);
        }
        .hero, .checkout { padding: 28px; }
        .hero { margin-bottom: 20px; }
        .eyebrow, .step {
            margin: 0 0 10px;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            font-size: 12px;
            color: var(--muted);
        }
        h1, h2, h3 { margin: 0; }
        h1 { font-size: clamp(2rem, 5vw, 3.4rem); line-height: 0.98; max-width: 10ch; }
        h2 { font-size: clamp(1.4rem, 3vw, 2rem); }
        h3 { font-size: 1.2rem; margin-bottom: 10px; }
        .summary {
            margin-top: 22px;
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        }
        .summary div, .field, .mock-panel {
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 14px 16px;
            background: rgba(255,255,255,0.82);
        }
        .summary span, .muted, label { color: var(--muted); font-size: 0.95rem; }
        .summary strong { display: block; margin-top: 8px; font-size: 1.15rem; }
        .checkout-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .gateway-options {
            display: grid;
            gap: 12px;
            width: 100%;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .gateway-choice {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(255,255,255,0.82);
            cursor: pointer;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .gateway-choice:hover {
            transform: translateY(-1px);
            border-color: rgba(239, 131, 84, 0.5);
        }
        .gateway-choice input {
            width: 18px;
            height: 18px;
            margin: 2px 0 0;
            accent-color: var(--accent);
            flex: 0 0 auto;
        }
        .gateway-choice-copy {
            display: grid;
            gap: 4px;
        }
        .gateway-choice-copy strong {
            font-size: 1rem;
        }
        .gateway-choice-copy small {
            color: var(--muted);
            font-size: 0.88rem;
        }
        .primary-button {
            border: 0;
            border-radius: 999px;
            background: var(--ink);
            color: white;
            padding: 14px 20px;
            font: inherit;
            cursor: pointer;
        }
        .primary-button:hover { background: var(--accent-dark); }
        .primary-button:disabled { opacity: 0.55; cursor: not-allowed; }
        .gateway-choice.is-selected {
            border-color: rgba(239, 131, 84, 0.8);
            box-shadow: 0 0 0 3px rgba(239, 131, 84, 0.18);
            background: rgba(255, 245, 239, 0.96);
        }
        .gateway-choice.is-disabled {
            opacity: 0.62;
            cursor: not-allowed;
            background: rgba(241, 244, 248, 0.92);
        }
        .gateway-choice.is-disabled:hover {
            transform: none;
            border-color: var(--line);
        }
        .card-panel { margin-top: 22px; }
        .hidden { display: none; }
        #fluidpay-payment-form {
            min-height: 220px;
        }
        .field-row {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .actions {
            margin-top: 18px;
            display: flex;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
        }
        input {
            width: 100%;
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--line);
            font: inherit;
        }
        @media (max-width: 700px) {
            .shell { padding: 20px 14px 32px; }
            .hero, .checkout { padding: 20px; border-radius: 22px; }
            .field-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    {{ $slot }}
</body>
</html>
