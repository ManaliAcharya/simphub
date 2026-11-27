<!DOCTYPE html>
<html lang="en">
<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#132238;padding:24px;">
    <div style="font-size:18px;font-weight:700;padding-bottom:16px;">
        JobTread connection broken
    </div>

    <div style="padding-bottom:16px;">
        SimpHub's request to JobTread for this client failed with an authentication error. This usually
        means the grant key was deleted, expired after a long period without use, or the JobTread user who
        created it was removed. Invoices for this client won't sync, and payments won't be recorded in
        JobTread, until this is fixed.
    </div>

    <div style="background:#f8fafc;border:1px solid #e4e6ef;border-radius:8px;padding:16px;margin-bottom:16px;">
        <div style="padding-bottom:6px;"><strong>Client:</strong> {{ $client->client_name }}</div>
        <div style="padding-bottom:6px;"><strong>PMS Client ID:</strong> {{ $client->pms_client_id }}</div>
        <div><strong>Error:</strong> {{ $errorMessage }}</div>
    </div>

    <div>
        To fix: have the merchant create a new grant key in JobTread (Settings &gt; Integrations &gt; API &gt;
        Grants) and paste it on this client's JobTread connection page.
    </div>
</body>
</html>
