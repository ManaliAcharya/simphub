<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BookSync — Client Login</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.08); width: 100%; max-width: 420px; padding: 40px; }
        .logo { font-size: 1.35rem; font-weight: 800; color: #111827; margin-bottom: 6px; letter-spacing: -.02em; }
        .logo span { color: #2563eb; }
        .subtitle { font-size: .88rem; color: #6b7280; margin-bottom: 32px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: .82rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
        input[type=email], input[type=password] {
            width: 100%; padding: 10px 14px; border: 1.5px solid #e5e7eb; border-radius: 8px;
            font-size: .92rem; color: #111827; background: #fff; outline: none; font-family: inherit;
            transition: border-color .15s;
        }
        input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .error-text { font-size: .8rem; color: #dc2626; margin-top: 5px; }
        .btn { width: 100%; padding: 11px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: .95rem; font-weight: 600; cursor: pointer; font-family: inherit; margin-top: 8px; transition: background .15s; }
        .btn:hover { background: #1d4ed8; }
        .alert { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; font-size: .85rem; color: #dc2626; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">Book<span>Sync</span></div>
    <p class="subtitle">Sign in to your client portal</p>

    @if($errors->any())
        <div class="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('booksync.portal.login.submit') }}">
        @csrf

        <div class="form-group">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn">Sign in</button>
    </form>
</div>
</body>
</html>
