{{-- Shared styles for the client API docs pages (Custom CRM tab, Zoho CRM subscription docs). --}}
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }

    /* ── Top nav tabs ── */
    .doc-nav { position:sticky; top:0; z-index:50; background:#fff; border-bottom:1px solid #e5e7eb; padding:0 32px; display:flex; gap:0; }
    .doc-nav a { display:inline-block; padding:14px 18px; font-size:13px; font-weight:500; color:#6b7280; text-decoration:none; border-bottom:2px solid transparent; white-space:nowrap; transition:color .15s,border-color .15s; }
    .doc-nav a:hover { color:#111827; }
    .doc-nav a.active { color:#2563eb; border-bottom-color:#2563eb; }

    /* ── Page header ── */
    .doc-header { padding:32px 32px 0; }
    .doc-header .eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#9ca3af; margin:0 0 6px; }
    .doc-header h1 { font-size:26px; font-weight:700; color:#111827; margin:0 0 6px; display:flex; align-items:center; gap:10px; }
    .mode-badge { font-size:11px; font-weight:600; padding:3px 10px; border-radius:999px; background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
    .doc-header .lead { font-size:14px; color:#6b7280; line-height:1.7; margin:0 0 28px; max-width:680px; }

    /* ── Client card ── */
    .client-card { margin:0 32px 32px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; display:grid; grid-template-columns:1fr 1fr; }
    .client-col { padding:20px 24px; display:flex; flex-direction:column; gap:20px; }
    .client-col-left { border-right:1px solid #e5e7eb; }
    .client-meta label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; display:block; margin-bottom:6px; }
    .copy-row { display:flex; align-items:center; gap:8px; }
    .copy-row code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; padding:5px 10px; color:#374151; }
    .copy-btn { padding:4px 10px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; transition:background .15s; }
    .copy-btn:hover { background:#1d4ed8; }
    .copy-btn.copied { background:#10b981; }
    .env-toggle { display:flex; border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; }
    .env-toggle span { padding:5px 14px; font-size:12px; font-weight:600; color:#6b7280; background:#f9fafb; cursor:default; }
    .env-toggle span.active { background:#eff6ff; color:#2563eb; }
    .gw-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 8px 3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
    .gw-remove-btn { background:none; border:none; cursor:pointer; color:#93c5fd; font-size:14px; line-height:1; padding:0; font-weight:700; transition:color .15s; }
    .gw-remove-btn:hover { color:#1d4ed8; }

    .add-gw-btn { margin-left:8px; padding:2px 10px; background:#ecfdf5; color:#059669; border:1px solid #6ee7b7; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; vertical-align:middle; transition:all .15s; }
    .add-gw-btn:hover { background:#d1fae5; }

    .add-gw-wrap { position:relative; display:inline-block; }
    .add-gw-pill-btn { display:inline-flex; align-items:center; gap:3px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#f9fafb; color:#6b7280; border:1px dashed #d1d5db; cursor:pointer; transition:all .15s; }
    .add-gw-pill-btn:hover { background:#eff6ff; color:#2563eb; border-color:#93c5fd; }
    .gw-dropdown { display:none; position:absolute; top:calc(100% + 6px); left:0; min-width:190px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.1); z-index:200; overflow:hidden; }
    .gw-dropdown.open { display:block; }
    .gw-dropdown-header { padding:7px 12px 5px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; background:#f9fafb; border-bottom:1px solid #f3f4f6; }
    .gw-dropdown-item { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; transition:background .12s; border-top:1px solid #f3f4f6; }
    .gw-dropdown-item:first-of-type { border-top:none; }
    .gw-dropdown-item:hover:not(.gw-item-added) { background:#eff6ff; color:#2563eb; }
    .gw-dropdown-item.gw-item-added { color:#9ca3af; cursor:default; }
    .gw-dropdown-check { color:#10b981; font-size:14px; font-weight:700; }

    /* ── Content shell ── */
    .doc-body { padding:0 32px 60px; }
    .doc-section { padding-top:40px; }
    .doc-section h2 { font-size:18px; font-weight:700; color:#111827; margin:0 0 16px; padding-bottom:10px; border-bottom:1px solid #e5e7eb; }

    /* ── Tables ── */
    .doc-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:24px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
    .doc-table th { text-align:left; padding:9px 14px; background:#f9fafb; color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #e5e7eb; }
    .doc-table td { padding:10px 14px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:top; font-size:13px; }
    .doc-table tr:last-child td { border-bottom:none; }
    .doc-table td code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; padding:2px 6px; border-radius:4px; }
    .badge-req  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#fee2e2; color:#991b1b; }
    .badge-opt  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#f3f4f6; color:#6b7280; }
    .badge-post { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#fef9c3; color:#854d0e; }

    /* ── Payment flow steps ── */
    .flow-steps { list-style:none; padding:0; margin:0 0 8px; }
    .flow-steps li { display:flex; gap:14px; align-items:flex-start; padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; line-height:1.6; }
    .flow-steps li:last-child { border-bottom:none; }
    .step-num { flex-shrink:0; width:24px; height:24px; border-radius:50%; background:#2563eb; color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; }
    .flow-steps li code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; padding:1px 6px; border-radius:4px; }

    /* ── Endpoint cards ── */
    .endpoint-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:12px; }
    .ep-header { display:flex; align-items:center; gap:12px; padding:14px 20px; cursor:pointer; user-select:none; }
    .ep-header:hover { background:#f9fafb; }
    .method-badge { display:inline-flex; align-items:center; justify-content:center; padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; letter-spacing:.04em; flex-shrink:0; }
    .method-post { background:#fef3c7; color:#92400e; }
    .method-get  { background:#dcfce7; color:#166534; }
    .ep-url  { font-family:ui-monospace,monospace; font-size:13px; color:#111827; font-weight:500; }
    .ep-desc { font-size:13px; color:#6b7280; margin-left:4px; }
    .chevron { margin-left:auto; flex-shrink:0; color:#9ca3af; transition:transform .2s; }
    .endpoint-card.open .chevron { transform:rotate(180deg); }
    .ep-body { display:none; border-top:1px solid #f3f4f6; padding:24px; }
    .endpoint-card.open .ep-body { display:block; }
    .ep-desc-text { font-size:13px; color:#6b7280; margin:0 0 22px; line-height:1.65; }
    .section-label { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.08em; margin:0 0 8px; }
    .schema-note { font-size:13px; color:#6b7280; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:14px 18px; }

    /* ── Code blocks ── */
    .code-block { background:#1e293b; border-radius:8px; padding:16px; overflow-x:auto; position:relative; margin-bottom:16px; }
    .code-block pre { margin:0; font-family:ui-monospace,monospace; font-size:12px; color:#e2e8f0; line-height:1.65; white-space:pre; }
    .copy-code { position:absolute; top:10px; right:10px; padding:3px 10px; background:#334155; color:#94a3b8; border:none; border-radius:5px; font-size:11px; cursor:pointer; transition:all .15s; }
    .copy-code:hover { background:#475569; color:#fff; }
    .copy-code.copied { background:#10b981; color:#fff; }
    .code-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin:0 0 8px; }
    .label-ok  { color:#10b981; }
    .label-err { color:#ef4444; }
    .label-req { color:#6b7280; }

    /* ── Webhook events ── */
    .event-code { font-family:ui-monospace,monospace; font-size:12px; }

    /* ── Error / info boxes ── */
    .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; font-size:13px; color:#1e40af; margin-bottom:20px; line-height:1.6; }
    .warn-box  { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:14px 18px; font-size:13px; color:#92400e; margin-bottom:20px; line-height:1.6; }

    /* ── Status badges ── */
    .status-4xx { display:inline-block; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:600; background:#fee2e2; color:#991b1b; }
    .status-5xx { display:inline-block; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:600; background:#fef3c7; color:#92400e; }

    @media(max-width:640px){
        .doc-nav { padding:0 16px; overflow-x:auto; }
        .doc-header, .doc-body { padding-left:16px; padding-right:16px; }
        .client-card { margin-left:16px; margin-right:16px; }
    }

    /* ── Tooltips ── */
    .doc-tip-wrap { position:relative; display:inline-flex; align-items:center; }
    .doc-tip-icon { width:14px; height:14px; border-radius:50%; background:rgba(0,0,0,.08); color:#9ca3af;
        font-size:9px; font-weight:700; display:inline-flex; align-items:center; justify-content:center;
        margin-left:5px; cursor:help; flex-shrink:0; user-select:none; }
    .doc-tip-box { display:none; position:absolute; left:calc(100% + 8px); top:50%; transform:translateY(-50%);
        background:#1e293b; color:#e2e8f0; font-size:11.5px; font-weight:400; line-height:1.6;
        padding:10px 13px; border-radius:10px; width:230px; z-index:400;
        text-transform:none; letter-spacing:0; box-shadow:0 4px 18px rgba(0,0,0,.22); pointer-events:none; }
    .doc-tip-box::before { content:''; position:absolute; right:100%; top:50%; transform:translateY(-50%);
        border:5px solid transparent; border-right-color:#1e293b; }
    .doc-tip-wrap:hover .doc-tip-box { display:block; }
</style>
