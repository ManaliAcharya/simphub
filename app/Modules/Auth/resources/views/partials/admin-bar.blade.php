{{-- Internal admin session bar: who is signed in + logout. Renders nothing for merchants (they use the merchant_session cookie, not the web guard). --}}
@auth('web')
    <style>
        .admin-bar {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            max-width: 980px;
            margin: 0 auto;
            padding: 16px 20px 0;
            box-sizing: border-box;
            font-size: 0.95rem;
            color: #5f7089;
        }

        .admin-bar strong {
            color: #132238;
        }

        .admin-bar form {
            margin: 0;
        }

        .admin-bar button {
            border: 1px solid rgba(19, 34, 56, 0.16);
            background: #fff;
            color: #132238;
            border-radius: 999px;
            padding: 8px 16px;
            font: inherit;
            cursor: pointer;
        }

        .admin-bar button:hover {
            background: #e9eef5;
        }

        .admin-bar button:focus-visible {
            outline: 2px solid #132238;
            outline-offset: 2px;
        }
    </style>

    <nav class="admin-bar" aria-label="Admin account">
        <span>Signed in as <strong>{{ auth('web')->user()->name }}</strong></span>

        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </nav>
@endauth
