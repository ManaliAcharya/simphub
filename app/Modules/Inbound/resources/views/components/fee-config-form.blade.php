{{--
  Shared fee configuration form.
  Props: $client, $formAction, $btnClass, $features (optional PmsFeatures instance)
--}}
@props(['client', 'formAction', 'btnClass' => 'button primary', 'features' => null])

@php
    $feeEnabled    = (bool) $client->fee_surcharge_enabled;
    $allowedModes  = $features ? $features->feeModes() : ['surcharge', 'cash_discount'];
    $feeMode       = $client->fee_mode ?? 'surcharge';
    // If the saved mode is no longer allowed for this provider, fall back to the first allowed mode.
    if (! in_array($feeMode, $allowedModes)) {
        $feeMode = $allowedModes[0] ?? 'surcharge';
    }
    $isCd          = $feeMode === 'cash_discount';
    $cashDiscountAllowed = in_array('cash_discount', $allowedModes);
    $cd           = (array) ($client->cash_discount_details ?? []);

    // Build gateway fee rows: group by fee type
    $gateways     = array_map('strtolower', $client->allowed_payment_gateways ?? []);
    $achGateways  = array_values(array_filter($gateways, fn($g) => $g === 'paya'));
    $cardGateways = array_values(array_filter($gateways, fn($g) => $g !== 'paya'));

    $ns = 'fcf-' . substr(md5($client->pms_client_id), 0, 6); // unique namespace per client
@endphp

<form method="POST" action="{{ $formAction }}" id="{{ $ns }}-form">
    @csrf

    {{-- ── Enable toggle ── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 13px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:12px;margin-bottom:14px;">
        <span style="font-size:13px;font-weight:600;color:#132238;">Enable Processing Fee</span>
        <div style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:11px;font-weight:700;">
            <input type="hidden" name="fee_surcharge_enabled" id="{{ $ns }}-enabled-val" value="{{ $feeEnabled ? '1' : '0' }}">
            <button type="button" id="{{ $ns }}-on"  onclick="fcfToggle('{{ $ns }}', true)"
                    style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;transition:all .15s;
                    {{ $feeEnabled ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
            <button type="button" id="{{ $ns }}-off" onclick="fcfToggle('{{ $ns }}', false)"
                    style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;transition:all .15s;
                    {{ $feeEnabled ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
        </div>
    </div>

    {{-- ── Fee section (visible when ON) ── --}}
    <div id="{{ $ns }}-section" style="{{ $feeEnabled ? '' : 'display:none;' }}">

        {{-- Fee Mode --}}
        <div style="margin-bottom:14px;">
            <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;margin:0 0 8px;">Fee Mode</p>
            <div style="display:grid;gap:7px;">
                <label style="display:flex;gap:10px;padding:10px 13px;border:1px solid {{ !$isCd ? 'rgba(19,34,56,.25)' : 'rgba(19,34,56,.1)' }};border-radius:10px;cursor:pointer;transition:border .15s;">
                    <input type="radio" name="fee_mode" value="surcharge" {{ !$isCd ? 'checked' : '' }}
                           onchange="fcfModeChange('{{ $ns }}')"
                           style="margin-top:2px;flex-shrink:0;">
                    <div>
                        <p style="margin:0;font-size:13px;font-weight:600;color:#132238;">Surcharge</p>
                        <p style="margin:2px 0 0;font-size:12px;color:#6b7c93;">Fee is added on top of the invoice amount at checkout.</p>
                    </div>
                </label>
                @if($cashDiscountAllowed)
                <label style="display:flex;gap:10px;padding:10px 13px;border:1px solid {{ $isCd ? 'rgba(19,34,56,.25)' : 'rgba(19,34,56,.1)' }};border-radius:10px;cursor:pointer;transition:border .15s;">
                    <input type="radio" name="fee_mode" value="cash_discount" {{ $isCd ? 'checked' : '' }}
                           onchange="fcfModeChange('{{ $ns }}')"
                           style="margin-top:2px;flex-shrink:0;">
                    <div>
                        <p style="margin:0;font-size:13px;font-weight:600;color:#132238;">Cash Discount</p>
                        <p style="margin:2px 0 0;font-size:12px;color:#6b7c93;">Gateway payments include the fee. Checkout also shows a cash/check option at the original amount with your offline payment details.</p>
                    </div>
                </label>
                @endif
            </div>
        </div>

        {{-- Gateway Fees — always show both rows --}}
        <div style="margin-bottom:14px;">
            <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;margin:0 0 8px;">Gateway Fees</p>
            <table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid rgba(19,34,56,.08);border-radius:10px;overflow:hidden;">
                <thead>
                    <tr style="background:#f8f9fb;">
                        <th style="text-align:left;padding:8px 12px;font-size:11px;font-weight:600;color:#6b7c93;letter-spacing:.04em;">Payment Type</th>
                        <th style="text-align:left;padding:8px 12px;font-size:11px;font-weight:600;color:#6b7c93;letter-spacing:.04em;width:130px;">Fee (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:9px 12px;color:#132238;font-weight:500;">
                            CC / Card
                            @if(count($cardGateways))
                                <span style="font-size:11px;color:#9ca3af;margin-left:4px;">({{ implode(', ', array_map('strtoupper', $cardGateways)) }})</span>
                            @endif
                        </td>
                        <td style="padding:7px 12px;">
                            <input type="number" name="cc_fee_percent" step="0.00001" min="0" max="999.99999"
                                   placeholder="0.00000"
                                   value="{{ old('cc_fee_percent', $client->cc_fee_percent) }}"
                                   style="width:100%;padding:6px 8px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;">
                        </td>
                    </tr>
                    <tr style="border-top:1px solid rgba(19,34,56,.06);">
                        <td style="padding:9px 12px;color:#132238;font-weight:500;">
                            ACH
                            @if(count($achGateways))
                                <span style="font-size:11px;color:#9ca3af;margin-left:4px;">({{ implode(', ', array_map('strtoupper', $achGateways)) }})</span>
                            @endif
                        </td>
                        <td style="padding:7px 12px;">
                            <input type="number" name="ach_fee_percent" step="0.00001" min="0" max="999.99999"
                                   placeholder="0.00000"
                                   value="{{ old('ach_fee_percent', $client->ach_fee_percent) }}"
                                   style="width:100%;padding:6px 8px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;">
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Exact-Cent Rounding (IOLTA / trust-account compliance) --}}
        @php $exactCentEnabled = (bool) $client->exact_cent_fee_rounding_enabled; @endphp
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 13px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:12px;margin-bottom:14px;">
            <div>
                <span style="font-size:13px;font-weight:600;color:#132238;">Exact-Cent Rounding</span>
                <p style="margin:2px 0 0;font-size:12px;color:#6b7c93;max-width:420px;">
                    For trust-account clients (e.g. IOLTA) who need the deposited amount to match
                    the invoice exactly. Uses bcmath decimal math and always rounds the total up to
                    the next cent, instead of rounding the fee to the nearest cent.
                </p>
            </div>
            <div style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:11px;font-weight:700;flex-shrink:0;margin-left:14px;">
                <input type="hidden" name="exact_cent_fee_rounding_enabled" id="{{ $ns }}-exact-cent-val" value="{{ $exactCentEnabled ? '1' : '0' }}">
                <button type="button" id="{{ $ns }}-exact-cent-on"
                        onclick="fcfExactCentToggle('{{ $ns }}', true)"
                        style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;transition:all .15s;
                        {{ $exactCentEnabled ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
                <button type="button" id="{{ $ns }}-exact-cent-off"
                        onclick="fcfExactCentToggle('{{ $ns }}', false)"
                        style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;transition:all .15s;
                        {{ $exactCentEnabled ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
            </div>
        </div>

        {{-- Disclosure Text (CC + ACH) --}}
        <div style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;display:block;margin-bottom:5px;">
                Fee Disclosure
                <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#9ca3af;"> — shown on payment page for CC &amp; ACH payments</span>
            </label>
            <textarea name="fee_disclosure" rows="2"
                      placeholder="e.g. A processing fee is applied to card and ACH payments."
                      style="width:100%;padding:8px 10px;border:1px solid rgba(19,34,56,.15);border-radius:9px;font:inherit;font-size:13px;resize:vertical;box-sizing:border-box;">{{ old('fee_disclosure', $client->fee_disclosure) }}</textarea>
        </div>

        {{-- Cash Discount Details (shown when cash_discount mode) --}}
        <div id="{{ $ns }}-cd-details" style="{{ $isCd ? '' : 'display:none;' }}">
            <div style="border-top:1px solid rgba(19,34,56,.08);padding-top:14px;margin-bottom:14px;">
                <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;margin:0 0 4px;">Cash / Check Payment Details</p>
                <p style="font-size:12px;color:#6b7c93;margin:0 0 10px;">Shown to customers who choose to pay offline without the fee.</p>
                <div style="display:grid;gap:9px;">
                    <div>
                        <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">Business name <span style="color:#9ca3af;">(payable to)</span></label>
                        <input type="text" name="cd_business_name" placeholder="Your business name"
                               value="{{ old('cd_business_name', $cd['business_name'] ?? '') }}"
                               style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">Mailing address</label>
                        <input type="text" name="cd_address" placeholder="Street address"
                               value="{{ old('cd_address', $cd['address'] ?? '') }}"
                               style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px;">
                        <div>
                            <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">City</label>
                            <input type="text" name="cd_city" placeholder="City"
                                   value="{{ old('cd_city', $cd['city'] ?? '') }}"
                                   style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">State</label>
                            <input type="text" name="cd_state" placeholder="IL" maxlength="2"
                                   value="{{ old('cd_state', $cd['state'] ?? '') }}"
                                   style="width:54px;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;text-transform:uppercase;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">Zip</label>
                            <input type="text" name="cd_zip" placeholder="60601"
                                   value="{{ old('cd_zip', $cd['zip'] ?? '') }}"
                                   style="width:80px;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div>
                            <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">Contact phone</label>
                            <input type="tel" name="cd_phone" placeholder="(312) 555-1234"
                                   value="{{ old('cd_phone', $cd['phone'] ?? '') }}"
                                   style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">Contact email</label>
                            <input type="email" name="cd_email" placeholder="billing@firm.com"
                                   value="{{ old('cd_email', $cd['email'] ?? '') }}"
                                   style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div>
                        <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">Additional instructions <span style="color:#9ca3af;">(optional)</span></label>
                        <textarea name="cd_instructions" rows="2" placeholder="e.g. Please include invoice number on your check."
                                  style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;resize:vertical;box-sizing:border-box;">{{ old('cd_instructions', $cd['instructions'] ?? '') }}</textarea>
                    </div>
                    <div>
                        <label style="font-size:12px;color:#6b7c93;display:block;margin-bottom:3px;">
                            Disclosure text <span style="color:#9ca3af;">(shown on payment page below the cash option)</span>
                        </label>
                        <textarea name="cd_disclosure" rows="2"
                                  placeholder="e.g. This merchant offers a discount for cash or check payments."
                                  style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.15);border-radius:8px;font:inherit;font-size:13px;resize:vertical;box-sizing:border-box;">{{ old('cd_disclosure', $cd['disclosure'] ?? '') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

    </div>{{-- end fee-section --}}

    <button type="submit" class="{{ $btnClass }}" style="font-size:12px;padding:7px 16px;margin-top:14px;">Save fee settings</button>


@error('cc_fee_percent')   <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('ach_fee_percent')  <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('fee_disclosure')   <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_business_name') <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_address')       <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_city')          <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_state')         <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_zip')           <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_phone')         <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
@error('cd_email')         <p style="font-size:11px;color:#9a2f2f;margin:4px 0 0;">{{ $message }}</p> @enderror
</form>

<script>
(function () {
    var ns = '{{ $ns }}';

    function fcfToggle(ns, on) {
        document.getElementById(ns + '-enabled-val').value = on ? '1' : '0';
        document.getElementById(ns + '-section').style.display = on ? '' : 'none';
        document.getElementById(ns + '-on').style.background  = on ? '#132238' : '#fff';
        document.getElementById(ns + '-on').style.color       = on ? '#fff'    : '#9ca3af';
        document.getElementById(ns + '-off').style.background = on ? '#fff'    : '#f1f5f9';
        document.getElementById(ns + '-off').style.color      = on ? '#9ca3af' : '#374151';
    }

    function fcfExactCentToggle(ns, on) {
        document.getElementById(ns + '-exact-cent-val').value = on ? '1' : '0';
        document.getElementById(ns + '-exact-cent-on').style.background  = on ? '#132238' : '#fff';
        document.getElementById(ns + '-exact-cent-on').style.color       = on ? '#fff'    : '#9ca3af';
        document.getElementById(ns + '-exact-cent-off').style.background = on ? '#fff'    : '#f1f5f9';
        document.getElementById(ns + '-exact-cent-off').style.color      = on ? '#9ca3af' : '#374151';
    }

    var CD_DEFAULT_DISCLOSURE = 'This merchant offers a discount for cash or check payments. Card and ACH payments include a processing fee.';

    function fcfModeChange(ns) {
        var mode = document.querySelector('#' + ns + '-form input[name=fee_mode]:checked');
        var show = mode && mode.value === 'cash_discount';
        var cdDiv = document.getElementById(ns + '-cd-details');
        if (cdDiv) cdDiv.style.display = show ? '' : 'none';

        // Pre-fill Fee Disclosure with default text when switching to cash discount
        if (show) {
            var discEl = document.querySelector('#' + ns + '-form textarea[name=fee_disclosure]');
            if (discEl && !discEl.value.trim()) {
                discEl.value = CD_DEFAULT_DISCLOSURE;
            }
        }
    }

    // Expose globally so onclick attributes work
    window.fcfToggle = fcfToggle;
    window.fcfModeChange = fcfModeChange;
    window.fcfExactCentToggle = fcfExactCentToggle;
})();
</script>
