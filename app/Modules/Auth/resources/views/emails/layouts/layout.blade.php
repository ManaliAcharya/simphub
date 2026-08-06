<style>
    html,
    body {
        padding: 0;
        margin: 0;
    }
</style>

<div
    style="font-family:Arial,Helvetica,sans-serif; line-height:1.6; font-size:15px; color:#2F3044; width:100%; background-color:#edf2f7; padding:40px 0;">

    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
        style="max-width:600px; margin:0 auto;">
        <tbody>

            <tr>
                <td align="center" style="padding-bottom:30px;">
                    <img src="{{ asset('images/logo/simphub-logo.jpeg') }}" alt="SimpHub" style="height:40px; border-radius:6px;">
                </td>
            </tr>

            <tr>
                <td>
                    <div style="background:#ffffff; border-radius:12px; padding:40px;">

                        @yield('email_content')

                        <div style="padding-top:30px;">
                            Kind regards,<br>
                            <strong>SimpHub Team</strong>
                        </div>

                    </div>
                </td>
            </tr>

            <tr>
                <td align="center" style="padding:20px; color:#7e8299; font-size:12px;">
                    © {{ date('Y') }} SimpHub. All rights reserved.
                </td>
            </tr>

        </tbody>
    </table>

</div>
