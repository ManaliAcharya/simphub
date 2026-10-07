<!DOCTYPE html>
<html>
<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#132238;padding:24px;">
    <div style="font-size:18px;font-weight:700;padding-bottom:16px;">
        Lawcus connection broken
    </div>

    <div style="padding-bottom:16px;">
        SimpHub tried to sync an invoice for this client from Lawcus and the request failed with an
        authentication error. This usually means the access token was deleted, or the Lawcus user who
        created it was removed. Invoices for this client will not sync until this is fixed.
    </div>

    <div style="background:#f8fafc;border:1px solid #e4e6ef;border-radius:8px;padding:16px;margin-bottom:16px;">
        <div style="padding-bottom:6px;"><strong>Client:</strong> {{ $client->client_name }}</div>
        <div style="padding-bottom:6px;"><strong>PMS Client ID:</strong> {{ $client->pms_client_id }}</div>
        <div><strong>Error:</strong> {{ $errorMessage }}</div>
    </div>

    <div>
        To fix: have the firm generate a fresh Lawcus access token from their account and re-enter it
        under this client's Lawcus connection page.
    </div>
</body>
</html>
