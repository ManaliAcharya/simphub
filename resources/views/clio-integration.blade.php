<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clio Integration</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('images/logo/simphub-favicon.jpeg') }}">
    <style>
        :root {
            --bg: #f4efe5;
            --panel: #fffaf2;
            --ink: #1d2a35;
            --muted: #5f6c76;
            --accent: #0d8b7f;
            --accent-strong: #0a6b62;
            --line: #dfd4c3;
            --success-bg: #e6f6ef;
            --success-ink: #1f6a48;
            --error-bg: #fdecea;
            --error-ink: #9d2f2b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(13, 139, 127, 0.12), transparent 32%),
                radial-gradient(circle at bottom right, rgba(210, 146, 68, 0.16), transparent 30%),
                linear-gradient(180deg, #f8f3ea 0%, var(--bg) 100%);
        }

        .shell {
            max-width: 960px;
            margin: 0 auto;
            padding: 40px 20px 72px;
        }

        .hero {
            background: linear-gradient(135deg, rgba(255, 250, 242, 0.95), rgba(255, 246, 234, 0.9));
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 60px rgba(33, 45, 54, 0.08);
        }

        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.16em;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 16px;
        }

        h1 {
            font-size: clamp(2.2rem, 5vw, 4.2rem);
            line-height: 0.95;
            margin: 0 0 16px;
        }

        .lead {
            max-width: 640px;
            font-size: 1.05rem;
            line-height: 1.7;
            color: var(--muted);
            margin: 0;
        }

        .actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 22px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: transform 0.18s ease, background 0.18s ease;
        }

        .button.primary {
            background: var(--accent);
            color: #fff;
        }

        .button.secondary {
            border: 1px solid var(--line);
            color: var(--ink);
            background: rgba(255, 255, 255, 0.62);
        }

        .button:hover { transform: translateY(-1px); }

        .grid {
            display: grid;
            gap: 18px;
            margin-top: 24px;
        }

        @media (min-width: 820px) {
            .grid {
                grid-template-columns: 1.1fr 0.9fr;
            }
        }

        .card {
            background: rgba(255, 250, 242, 0.92);
            border: 1px solid var(--line);
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 14px 42px rgba(33, 45, 54, 0.06);
        }

        .card h2 {
            margin: 0 0 16px;
            font-size: 1.2rem;
        }

        .status {
            display: inline-flex;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.92rem;
            font-weight: 700;
        }

        .status.ok {
            background: var(--success-bg);
            color: var(--success-ink);
        }

        .status.warn {
            background: #fff2d9;
            color: #8a5a00;
        }

        .flash {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            line-height: 1.5;
        }

        .flash.success {
            background: var(--success-bg);
            color: var(--success-ink);
        }

        .flash.error {
            background: var(--error-bg);
            color: var(--error-ink);
        }

        .kv {
            display: grid;
            gap: 14px;
        }

        .kv div {
            padding-top: 14px;
            border-top: 1px solid rgba(223, 212, 195, 0.7);
        }

        .kv dt {
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .kv dd {
            margin: 0;
            word-break: break-word;
            font-size: 1rem;
        }

        code {
            display: block;
            padding: 12px 14px;
            margin-top: 8px;
            border-radius: 12px;
            background: rgba(29, 42, 53, 0.06);
            color: #243746;
            font-family: Consolas, "Courier New", monospace;
            font-size: 0.92rem;
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="eyebrow">Inbound / Clio PMS</div>
            <h2>Connect Clio and register invoice webhook.</h2>
            <p class="lead">
                This screen starts the Clio OAuth flow, stores the access token in Laravel, and registers a webhook for invoice creation events so your middleware can ingest them automatically.
            </p>

            @if ($success)
                <div class="flash success">{{ $success }}</div>
            @endif

            @if ($error)
                <div class="flash error">{{ $error }}</div>
            @endif

            <div class="actions">
                <a class="button primary" href="{{ $connectUrl }}">Connect Clio</a>
                <!-- <a class="button secondary" href="{{ $webhookUrl }}">Open Webhook URL</a> -->
            </div>
        </section>

        <!-- <section class="grid">
            <article class="card">
                <h2>Connection Status</h2>
                <p>
                    @if ($connection?->access_token)
                        <span class="status ok">Connected</span>
                    @else
                        <span class="status warn">Not connected yet</span>
                    @endif
                </p>

                <dl class="kv">
                    <div>
                        <dt>Token Expires At</dt>
                        <dd>{{ optional($connection?->token_expires_at)?->toDateTimeString() ?? 'Not available' }}</dd>
                    </div>
                    <div>
                        <dt>Webhook ID</dt>
                        <dd>{{ $connection?->webhook_id ?? 'Not registered yet' }}</dd>
                    </div>
                    <div>
                        <dt>Webhook Expires At</dt>
                        <dd>{{ optional($connection?->webhook_expires_at)?->toDateTimeString() ?? 'Not available' }}</dd>
                    </div>
                    <div>
                        <dt>Webhook Secret Stored</dt>
                        <dd>{{ $connection?->webhook_secret ? 'Yes' : 'No' }}</dd>
                    </div>
                </dl>
            </article>

            <article class="card">
                <h2>Clio App Setup</h2>
                <dl class="kv">
                    <div>
                        <dt>Redirect URI</dt>
                        <dd><code>{{ $callbackUrl }}</code></dd>
                    </div>
                    <div>
                        <dt>Webhook Target URL</dt>
                        <dd><code>{{ $webhookUrl }}</code></dd>
                    </div>
                    <div>
                        <dt>Connect Entry</dt>
                        <dd><code>{{ $connectUrl }}</code></dd>
                    </div>
                </dl>
            </article>
        </section> -->
    </main>
</body>
</html>
