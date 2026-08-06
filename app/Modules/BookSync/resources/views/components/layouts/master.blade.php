@props(['title' => 'BookSync'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — SimpHub</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('images/logo/simphub-favicon.jpeg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        body { margin:0; font-family:"Space Grotesk",sans-serif; background:linear-gradient(180deg,#f6f0ea 0%,#f8fbff 100%); color:#132238; }
        .shell { max-width:960px; margin:0 auto; padding:32px 20px 48px; }
        .panel { background:rgba(255,255,255,.94); border:1px solid rgba(19,34,56,.1); border-radius:26px; padding:24px; box-shadow:0 18px 46px rgba(19,34,56,.08); margin-bottom:18px; }
        .eyebrow { margin:0 0 8px; text-transform:uppercase; letter-spacing:.14em; font-size:12px; color:#6b7c93; }
        h1 { margin:0 0 8px; font-size:clamp(1.6rem,3vw,2.4rem); }
        h2 { margin:0 0 14px; font-size:1.2rem; }
        .copy { margin:0 0 20px; color:#5f7089; max-width:70ch; }
        .field { background:#fff; border:1px solid rgba(19,34,56,.1); border-radius:18px; padding:14px 16px; margin-bottom:10px; word-break:break-all; }
        .field span { display:block; color:#6b7c93; font-size:.9rem; margin-bottom:4px; }
        .field strong, .field code { display:block; font-size:.95rem; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .button { display:inline-flex; align-items:center; gap:6px; padding:10px 22px; border-radius:100px; font-family:inherit; font-size:.9rem; font-weight:600; cursor:pointer; text-decoration:none; border:none; transition:opacity .15s; }
        .button:hover { opacity:.85; }
        .btn-primary { background:#132238; color:#fff; }
        .btn-secondary { background:rgba(19,34,56,.08); color:#132238; }
        .btn-danger { background:#c0392b; color:#fff; }
        .btn-success { background:#27ae60; color:#fff; }
        .badge { display:inline-block; padding:3px 10px; border-radius:100px; font-size:.78rem; font-weight:600; }
        .badge-active { background:#d5f5e3; color:#1e8449; }
        .badge-inactive, .badge-disabled { background:#f2d7d5; color:#922b21; }
        .badge-pending, .badge-pending_qb_connect { background:#fef9e7; color:#9a7d0a; }
        .badge-expired, .badge-qb_token_expired { background:#fdf2f8; color:#76448a; }
        .table { width:100%; border-collapse:collapse; font-size:.9rem; }
        .table th { text-align:left; padding:10px 14px; font-weight:600; border-bottom:2px solid rgba(19,34,56,.08); color:#6b7c93; font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; }
        .table td { padding:10px 14px; border-bottom:1px solid rgba(19,34,56,.06); }
        .table tr:last-child td { border-bottom:none; }
        .alert { border-radius:14px; padding:14px 18px; margin-bottom:16px; font-size:.92rem; }
        .alert-success { background:#d5f5e3; color:#1a5c32; border:1px solid #a9dfbf; }
        .alert-warning { background:#fef9e7; color:#7d6608; border:1px solid #f9e79f; }
        .alert-error   { background:#fdf2f8; color:#76448a; border:1px solid #d7bde2; }
        form .form-group { margin-bottom:14px; }
        form label { display:block; font-weight:600; font-size:.9rem; margin-bottom:5px; }
        form input, form select { width:100%; padding:10px 14px; border:1px solid rgba(19,34,56,.15); border-radius:12px; font-family:inherit; font-size:.95rem; box-sizing:border-box; background:#fff; }
        form input:focus, form select:focus { outline:2px solid #132238; }
        .nav { display:flex; gap:8px; margin-bottom:22px; }
        .nav a { text-decoration:none; color:#6b7c93; font-size:.9rem; }
        .nav a:hover { color:#132238; }
        .nav .sep { color:#c5cfd8; }
    </style>
</head>
<body>
<div class="shell">
    {{ $slot }}
</div>
</body>
</html>
