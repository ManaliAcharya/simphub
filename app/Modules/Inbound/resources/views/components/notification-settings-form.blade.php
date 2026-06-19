@props(['client', 'formAction'])

@php
    $enabled    = (bool) ($client->payment_link_override_enabled ?? false);
    $recipient  = in_array($client->payment_link_recipient ?? '', ['admin','both']) ? $client->payment_link_recipient : 'admin';
    $adminEmail = $client->payment_link_admin_email ?? '';
    $ns         = 'plr-' . substr(md5($client->pms_client_id), 0, 6);
@endphp

<div class="cc-card">
    <div class="cc-card-title">Payment Link</div>

    <form method="POST" action="{{ $formAction }}" id="{{ $ns }}-form">
        @csrf

        {{-- ── Enable toggle ── --}}
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 13px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:12px;margin-bottom:14px;">
            <div>
                <span style="font-size:13px;font-weight:600;color:#132238;">Payment link recipient override</span>
                <p style="margin:2px 0 0;font-size:12px;color:#6b7c93;">Off sends to customer as normal. On lets you redirect to admin or send both.</p>
            </div>
            <div style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:11px;font-weight:700;flex-shrink:0;margin-left:16px;">
                <input type="hidden" name="payment_link_override_enabled" id="{{ $ns }}-enabled-val" value="{{ $enabled ? '1' : '0' }}">
                <button type="button" id="{{ $ns }}-on"  onclick="plrToggle('{{ $ns }}', true)"
                        style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;transition:all .15s;
                        {{ $enabled ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">On</button>
                <button type="button" id="{{ $ns }}-off" onclick="plrToggle('{{ $ns }}', false)"
                        style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;transition:all .15s;
                        {{ $enabled ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">Off</button>
            </div>
        </div>

        {{-- ── Config section (visible when ON) ── --}}
        <div id="{{ $ns }}-section" style="{{ $enabled ? '' : 'display:none;' }}">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:end;margin-bottom:14px;">

                {{-- Link recipient dropdown --}}
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:#6b7c93;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">
                        Link recipient
                    </label>
                    <select name="payment_link_recipient" id="{{ $ns }}-recipient"
                        style="width:100%;padding:9px 12px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font-size:13px;color:#132238;background:#fff;font-family:inherit;">
                        <option value="admin" {{ $recipient === 'admin' ? 'selected' : '' }}>Admin / Self</option>
                        <option value="both"  {{ $recipient === 'both'  ? 'selected' : '' }}>Both</option>
                    </select>
                </div>

                {{-- Admin email input — always visible when section is on --}}
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:#6b7c93;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">
                        Admin email for payment links
                    </label>
                    <input type="email" name="payment_link_admin_email" id="{{ $ns }}-email"
                        value="{{ $adminEmail }}"
                        placeholder="billing@accountingfirm.com"
                        required
                        style="width:100%;padding:9px 12px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font-size:13px;color:#132238;font-family:inherit;box-sizing:border-box;">
                </div>

            </div>

            @if($errors->has('payment_link_recipient') || $errors->has('payment_link_admin_email'))
            <div style="padding:10px 14px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;font-size:13px;color:#991b1b;margin-bottom:14px;">
                {{ $errors->first('payment_link_recipient') ?: $errors->first('payment_link_admin_email') }}
            </div>
            @endif
        </div>

        <button type="submit" class="button primary" style="font-size:13px;">Save</button>
    </form>
</div>

<script>
function plrToggle(ns, on) {
    document.getElementById(ns + '-enabled-val').value = on ? '1' : '0';
    document.getElementById(ns + '-on').style.cssText  += on
        ? 'background:#132238;color:#fff;'  : 'background:#fff;color:#9ca3af;';
    document.getElementById(ns + '-off').style.cssText += on
        ? 'background:#fff;color:#9ca3af;'  : 'background:#f1f5f9;color:#374151;';
    document.getElementById(ns + '-on').style.background  = on ? '#132238' : '#fff';
    document.getElementById(ns + '-on').style.color       = on ? '#fff'    : '#9ca3af';
    document.getElementById(ns + '-off').style.background = on ? '#fff'    : '#f1f5f9';
    document.getElementById(ns + '-off').style.color      = on ? '#9ca3af' : '#374151';
    document.getElementById(ns + '-section').style.display = on ? '' : 'none';
}


</script>
