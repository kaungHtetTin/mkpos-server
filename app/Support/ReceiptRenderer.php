<?php

namespace App\Support;

class ReceiptRenderer
{
    public static function render(array $sale, array $settings): array
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $enabled = fn ($value) => ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
        $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
        $showCustomer = $enabled($settings['receipt_show_customer']) && ! empty($sale['customer_name']);
        $showPayment = $enabled($settings['receipt_show_payment_method']);
        $showPriceType = $enabled($settings['receipt_show_price_type']);
        $lines = array_values(array_filter([
            $settings['shop_name'], $settings['shop_title'], $settings['shop_address'], $settings['shop_phone'],
            'Receipt: '.$sale['receipt_no'], 'Date: '.$sale['created_at'],
            $showCustomer ? 'Customer: '.$sale['customer_name'] : null,
            $showPayment ? 'Payment: '.$sale['payment_method'] : null,
        ], fn ($line) => $line !== null && trim((string) $line) !== ''));
        $rows = '';
        foreach ($sale['items'] as $item) {
            $itemQuantity = $quantity($item['quantity']);
            $lines[] = $item['product_name'].' x '.$itemQuantity.'  '.number_format($item['line_total']).' '.$settings['currency'];
            if ($showPriceType) {
                $lines[] = '  Price type: '.($item['price_type'] ?: 'Retail');
            }
            if ((float) $item['foc_quantity'] > 0) {
                $lines[] = '  FOC: '.$quantity($item['foc_quantity']);
            }
            $rows .= '<div class="receipt-item"><div class="receipt-item-name">'.$escape($item['product_name']).'</div>'
                .($showPriceType ? '<div class="receipt-row"><span>Price type</span><strong>'.$escape($item['price_type'] ?: 'Retail').'</strong></div>' : '')
                .'<div class="receipt-row receipt-item-amount"><span>'.$escape($itemQuantity).' × '.number_format($item['unit_price']).'</span><strong>'.number_format($item['line_total']).' '.$escape($settings['currency']).'</strong></div>'
                .((float) $item['foc_quantity'] > 0 ? '<div class="receipt-foc">FOC: '.$escape($quantity($item['foc_quantity'])).'</div>' : '').'</div>';
        }
        if ((int) $sale['discount'] > 0) {
            $lines[] = 'Discount: -'.number_format($sale['discount']).' '.$settings['currency'];
        }
        $lines[] = 'Total: '.number_format($sale['total']).' '.$settings['currency'];
        if (trim((string) $settings['receipt_footer']) !== '') {
            $lines[] = $settings['receipt_footer'];
        }
        $html = '<div class="receipt-document"><header class="receipt-shop-header"><div class="shop-name">'.$escape($settings['shop_name']).'</div>'
            .(trim((string) $settings['shop_title']) !== '' ? '<div class="shop-title">'.$escape($settings['shop_title']).'</div>' : '')
            .(trim((string) $settings['shop_address']) !== '' ? '<div class="shop-address">'.$escape($settings['shop_address']).'</div>' : '')
            .(trim((string) $settings['shop_phone']) !== '' ? '<div class="shop-phone">'.$escape($settings['shop_phone']).'</div>' : '').'</header>'
            .'<div class="receipt-rule"></div><div class="receipt-meta"><div class="receipt-row"><span>Receipt</span><strong>'.$escape($sale['receipt_no']).'</strong></div>'
            .'<div class="receipt-row"><span>Date</span><strong>'.$escape($sale['created_at']).'</strong></div>'
            .($showCustomer ? '<div class="receipt-row"><span>Customer</span><strong>'.$escape($sale['customer_name']).'</strong></div>' : '')
            .($showPayment ? '<div class="receipt-row"><span>Payment</span><strong>'.$escape($sale['payment_method']).'</strong></div>' : '').'</div>'
            .'<div class="receipt-rule"></div><div class="receipt-items">'.$rows.'</div><div class="receipt-rule"></div><div class="receipt-totals">'
            .'<div class="receipt-row"><span>Subtotal</span><strong>'.number_format($sale['subtotal']).' '.$escape($settings['currency']).'</strong></div>'
            .((int) $sale['discount'] > 0 ? '<div class="receipt-row"><span>Discount</span><strong>-'.number_format($sale['discount']).' '.$escape($settings['currency']).'</strong></div>' : '')
            .'<div class="receipt-row receipt-total"><span>Total</span><strong>'.number_format($sale['total']).' '.$escape($settings['currency']).'</strong></div>'
            .'<div class="receipt-row"><span>Paid</span><strong>'.number_format($sale['paid_amount']).' '.$escape($settings['currency']).'</strong></div>'
            .((int) $sale['credit_amount'] > 0 ? '<div class="receipt-row"><span>Credit</span><strong>'.number_format($sale['credit_amount']).' '.$escape($settings['currency']).'</strong></div>' : '').'</div>'
            .(trim((string) $settings['receipt_footer']) !== '' ? '<div class="receipt-rule"></div><div class="receipt-footer">'.$escape($settings['receipt_footer']).'</div>' : '').'</div>';

        $layout = ['paper_size' => $settings['receipt_paper_size'], 'header_alignment' => $settings['receipt_header_alignment'],
            'margin_left' => (float) $settings['receipt_margin_left'], 'margin_right' => (float) $settings['receipt_margin_right'],
            'header_font_size' => (float) $settings['receipt_header_font_size'], 'body_font_size' => (float) $settings['receipt_body_font_size'],
            'line_height' => (float) $settings['receipt_line_height']];

        return ['sale' => $sale, 'text' => implode("\n", $lines), 'html' => $html, 'paper_size' => $settings['receipt_paper_size'], 'layout' => $layout];
    }
}
