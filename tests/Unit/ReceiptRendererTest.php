<?php

namespace Tests\Unit;

use App\Support\ReceiptRenderer;
use PHPUnit\Framework\TestCase;

class ReceiptRendererTest extends TestCase
{
    public function test_receipt_escapes_content_and_preserves_layout_and_credit_totals(): void
    {
        $settings = [
            'shop_name' => '<Shop>', 'shop_title' => '', 'shop_address' => '', 'shop_phone' => '', 'currency' => 'Ks',
            'receipt_footer' => "Thank you\nမြန်မာစာ", 'receipt_show_customer' => '1', 'receipt_show_payment_method' => '1',
            'receipt_show_price_type' => '1', 'receipt_paper_size' => '58mm', 'receipt_header_alignment' => 'left',
            'receipt_margin_left' => 2, 'receipt_margin_right' => 2, 'receipt_header_font_size' => 12,
            'receipt_body_font_size' => 8, 'receipt_line_height' => 1.5,
            'receipt_logo_data_url' => 'data:image/png;base64,aGVsbG8=',
        ];
        $sale = [
            'id' => 1, 'receipt_no' => 'TEST', 'created_at' => '2026-09-05', 'customer_name' => '<Customer>', 'payment_method' => 'Cash',
            'subtotal' => 1000, 'discount' => 100, 'total' => 900, 'paid_amount' => 500, 'credit_amount' => 400,
            'items' => [['product_name' => '<script>alert(1)</script>', 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000, 'price_type' => 'Retail', 'foc_quantity' => 1]],
        ];
        $receipt = ReceiptRenderer::render($sale, $settings);
        $this->assertStringNotContainsString('<script>', $receipt['html']);
        $this->assertStringContainsString('&lt;Shop&gt;', $receipt['html']);
        $this->assertStringContainsString('400 Ks', $receipt['html']);
        $this->assertStringContainsString('receipt-item', $receipt['html']);
        $this->assertStringContainsString('class="receipt-logo"', $receipt['html']);
        $this->assertStringContainsString('class="receipt-document-heading"', $receipt['html']);
        $this->assertStringContainsString('class="receipt-items-table"', $receipt['html']);
        $this->assertStringContainsString('class="receipt-page-footer"', $receipt['html']);
        $this->assertStringContainsString('မြန်မာစာ', $receipt['html']);
        $this->assertSame('58mm', $receipt['layout']['paper_size']);
        $this->assertSame(12.0, $receipt['layout']['header_font_size']);
        $settings['receipt_show_customer'] = '0';
        $this->assertStringNotContainsString('&lt;Customer&gt;', ReceiptRenderer::render($sale, $settings)['html']);
    }
}
