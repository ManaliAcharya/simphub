{{-- FluidPay MID Identifier / Processor ID picker for Gateway Credentials, filled from FluidPay by
     fluidpay-mid-select-script: the MIDs the key can act for, then the chosen MID's processors.
     Expects $prefix (csc-cred | csc-trust), $fundType (OPERATING | TRUST), $field
     (mid_identifier | processor_id), $name and $value. --}}
@php $isMid = $field === 'mid_identifier'; @endphp
<select id="{{ $prefix }}-fluidpay-{{ $field }}"
        name="{{ $name }}"
        class="csc-fp-lookup"
        data-lookup="{{ $isMid ? 'merchants' : 'processors' }}"
        data-fund-type="{{ $fundType }}"
        data-api-input="{{ $prefix }}-fluidpay-api_key"
        data-mid-input="{{ $prefix }}-fluidpay-mid_identifier"
        data-status="{{ $prefix }}-fluidpay-{{ $field }}-status"
        data-current="{{ $value }}"
        style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.12);border-radius:8px;font:inherit;font-size:13px;">
    <option value="">{{ $isMid ? 'Select MID…' : 'Select processor…' }}</option>
    @if($value)
        <option value="{{ $value }}" selected>{{ $value }} (saved)</option>
    @endif
</select>
<div id="{{ $prefix }}-fluidpay-{{ $field }}-status" role="status" aria-live="polite"
     style="font-size:11px;color:#6b7c93;margin-top:4px;"></div>
