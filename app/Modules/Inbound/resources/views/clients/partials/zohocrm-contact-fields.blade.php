{{-- Zoho CRM Contact fields written after each subscription payment. Expects $crmFields, $crmFieldsSetup, $crmFieldsSetupError, $connectUrl. --}}
@php
    $typeLabels = ['text' => 'Single Line', 'website' => 'URL', 'date' => 'Date'];
@endphp
<div class="cc-card">
    <div class="cc-card-title">Contact Fields</div>
    <div class="cc-card-desc">
        After each subscription payment, these fields are updated on the customer's Contact in Zoho CRM.
        The Contact is found by the <code>customer_unique_id</code> sent at subscribe (when it is a Zoho Contact ID),
        otherwise by email.
    </div>

    @if ($crmFieldsSetup === 'failed')
        <div class="cc-notice error" role="alert" style="margin-bottom:12px;">
            The fields could not be added automatically: {{ $crmFieldsSetupError }}
        </div>
        <div style="font-size:13px;color:#374151;margin-bottom:12px;">
            Ask a Zoho CRM administrator to open <strong>Setup &rarr; Modules and Fields &rarr; Contacts</strong>
            and add the fields below with these exact labels, then click <strong>Reconnect</strong>.
            Reconnecting with an administrator account adds them automatically.
        </div>
    @endif

    <table class="cc-table">
        <thead>
            <tr>
                <th scope="col">Field label</th>
                <th scope="col">Type</th>
                <th scope="col">API name</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($crmFields as $field)
                <tr>
                    <td>{{ $field['label'] }}</td>
                    <td>{{ $typeLabels[$field['type']] ?? $field['type'] }}</td>
                    <td>
                        @if ($field['api_name'])
                            <code>{{ $field['api_name'] }}</code>
                        @else
                            <span style="color:#92400e;">Not set up</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
