<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Tests\TestCase;

class PaymentLinkEmailLineBreaksTest extends TestCase
{
    private function render(?string $header, ?string $footer): string
    {
        $invoice = (new Invoice)->forceFill([
            'invoice_number' => '1653',
            'amount_cents'   => 14585,
            'currency'       => 'USD',
        ]);

        return view('payment::emails.payment-link', [
            'invoice'      => $invoice,
            'paymentUrl'   => 'https://example.com/pay/test',
            'bodyHeader'   => $header,
            'bodyFooter'   => $footer,
            'merchantName' => 'Test Merchant',
        ])->render();
    }

    public function test_header_and_footer_keep_the_merchants_line_breaks(): void
    {
        $html = $this->render("Dear Jane,\n\nThanks for your business.", "Have a great day!\r\nTest Merchant LLC");

        $this->assertStringContainsString("Dear Jane,<br />\n<br />\nThanks for your business.", $html);
        $this->assertStringContainsString("Have a great day!<br />\r\nTest Merchant LLC", $html);
    }

    public function test_header_and_footer_html_is_still_escaped(): void
    {
        $html = $this->render("<script>alert(1)</script>\nHi", '<b>Bold</b>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br />', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
    }
}
