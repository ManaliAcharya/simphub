{{-- Zoho CRM Contact field mapping for the values written after each subscription payment.
     Expects $client, $crmFields, $crmFieldsSetup, $crmFieldsSetupError, $crmFieldsManual, $crmFieldOptionsError. --}}
@php
    $typeLabels = [
        'text' => 'Single Line', 'textarea' => 'Multi Line', 'website' => 'URL',
        'date' => 'Date', 'datetime' => 'Date/Time',
    ];
@endphp
<div class="cc-card">
    <div class="cc-card-title">Contact Fields</div>
    <div class="cc-card-desc">
        After each subscription payment, these values are written to the customer's Contact in Zoho CRM.
        Choose the Contact field that receives each value, or <em>Don't sync</em> to skip it.
        The Contact is found by the <code>customer_unique_id</code> sent at subscribe (when it is a Zoho Contact ID),
        otherwise by email.
    </div>

    @if ($crmFieldsSetup === 'failed' && ! $crmFieldsManual)
        <div class="cc-notice error" role="alert" style="margin-bottom:12px;">
            The SimpHub fields could not be added automatically: {{ $crmFieldsSetupError }}
        </div>
        <div style="font-size:13px;color:#374151;margin-bottom:12px;">
            Map the values to existing Contact fields below, or ask a Zoho CRM administrator to click
            <strong>Reconnect</strong> so the SimpHub fields are added automatically.
        </div>
    @endif

    @if ($crmFieldOptionsError)
        <div class="cc-notice error" role="alert" style="margin-bottom:12px;">
            Could not load Contact fields from Zoho CRM: {{ $crmFieldOptionsError }}
        </div>

        <table class="cc-table">
            <thead>
                <tr>
                    <th scope="col">SimpHub value</th>
                    <th scope="col">Zoho CRM field (API name)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($crmFields as $field)
                    <tr>
                        <td>{{ $field['label'] }}</td>
                        <td>
                            @if ($field['api_name'])
                                <code>{{ $field['api_name'] }}</code>
                            @else
                                <span style="color:#92400e;">Not synced</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <form method="POST" action="{{ route('inbound.zohocrm.contact-fields') }}">
            @csrf
            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">

            <table class="cc-table" style="margin-bottom:12px;">
                <thead>
                    <tr>
                        <th scope="col">SimpHub value</th>
                        <th scope="col">Zoho CRM Contact field</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($crmFields as $field)
                        @php
                            $selectId = 'crm-field-' . $field['key'];
                            $current = $field['api_name'];
                            $currentMissing = $current && ! collect($field['options'])->contains('api_name', $current);
                        @endphp
                        <tr>
                            <td>
                                <label for="{{ $selectId }}" style="font-weight:600;">{{ $field['label'] }}</label>
                                <div style="font-size:12px;color:#6b7280;">{{ $field['description'] }}</div>
                            </td>
                            <td>
                                <div class="cc-field" style="margin-bottom:0;">
                                    <select id="{{ $selectId }}" name="contact_fields[{{ $field['key'] }}]"
                                        @if ($currentMissing) aria-describedby="{{ $selectId }}-missing" @endif>
                                        <option value="">Don't sync</option>
                                        @foreach ($field['options'] as $option)
                                            <option value="{{ $option['api_name'] }}" @selected($current === $option['api_name'])>
                                                {{ $option['label'] }} ({{ $option['api_name'] }}) · {{ $typeLabels[$option['type']] ?? $option['type'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                @if ($currentMissing)
                                    <div id="{{ $selectId }}-missing" style="font-size:12px;color:#b91c1c;margin-top:4px;">
                                        Currently mapped to <code>{{ $current }}</code>, which is no longer a writable
                                        Contact field in Zoho CRM. Choose another field and save.
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <button type="submit" class="button primary" style="font-size:13px;">Save mapping</button>
        </form>
    @endif
</div>
