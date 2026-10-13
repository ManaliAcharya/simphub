<x-inbound::layouts.master :title="'API Docs — ' . $client->client_name">
@include('inbound::clients.partials.api-docs-styles')
<style>
    .page-header { background:#1a1a2e; padding:20px 32px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .page-header h1 { color:#fff; font-size:18px; font-weight:700; margin:0; }
    .breadcrumb { font-size:13px; color:rgba(255,255,255,.7); margin-bottom:4px; }
    .breadcrumb a { color:rgba(255,255,255,.85); text-decoration:none; }
    .breadcrumb a:hover, .breadcrumb a:focus { color:#fff; text-decoration:underline; }
    .print-btn { padding:7px 14px; background:#fff; color:#1a1a2e; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; }
    .doc-nav { top:0; overflow-x:auto; }
    @media (max-width:720px) { .page-header { padding:16px; } }
    @media print { .page-header .print-btn, .doc-nav, .copy-code { display:none; } .endpoint-card .ep-body { display:block !important; } }
</style>

<header class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="{{ route('inbound.clients.api-docs', ['pms_client_id' => $client->pms_client_id]) }}">{{ $client->client_name }}</a>
            &rsaquo; Subscription API
        </div>
        <h1>Subscription API Docs</h1>
    </div>
    <button type="button" class="print-btn" onclick="window.print()">Print / Save PDF</button>
</header>

<main>
    @include('inbound::clients.partials.subscription-api-docs')
</main>

<script>
    function toggleEp(id) {
        document.getElementById(id).classList.toggle('open');
    }
    function setActive(el) {
        document.querySelectorAll('.doc-nav a').forEach(a => a.classList.remove('active'));
        el.classList.add('active');
    }
    function copyCode(btn) {
        navigator.clipboard.writeText(btn.nextElementSibling.textContent.trim()).then(function () {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }

    // Highlight active nav on scroll
    const sections = ['auth', 'flow', 'endpoints', 'errors'];
    window.addEventListener('scroll', function () {
        let current = 'auth';
        sections.forEach(function (id) {
            const el = document.getElementById(id);
            if (el && el.getBoundingClientRect().top <= 80) current = id;
        });
        document.querySelectorAll('.doc-nav a').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + current);
        });
    });
</script>
</x-inbound::layouts.master>
