<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends ApiController
{
    private const DEFAULTS = [
        'shop_name' => 'MKPOS Shop', 'shop_title' => '', 'shop_address' => '', 'shop_phone' => '', 'currency' => 'Ks',
        'payment_methods' => 'Cash,Wallet Pay,Banking Pay,KPay,Wave Pay,Credit', 'price_types' => 'Retail', 'receipt_footer' => '',
        'receipt_show_customer' => '1', 'receipt_show_payment_method' => '1', 'receipt_show_price_type' => '1',
        'receipt_paper_size' => '80mm', 'receipt_header_alignment' => 'center', 'receipt_margin_left' => '3', 'receipt_margin_right' => '3',
        'receipt_header_font_size' => '11', 'receipt_body_font_size' => '8.2', 'receipt_line_height' => '1.45',
        'printer_name' => '', 'language' => 'en',
    ];

    public function index(): array
    {
        $settings = array_merge(self::DEFAULTS, DB::table('settings')->where('key', '<>', 'admin_pin_hash')->pluck('value', 'key')->all());
        $settings['admin_pin_set'] = DB::table('settings')->where('key', 'admin_pin_hash')->exists() ? '1' : '0';

        return $settings;
    }

    public function update(Request $request): array
    {
        $data = $request->validate(['shop_name' => ['required', 'string', 'max:255'], 'shop_title' => ['nullable', 'string'], 'shop_address' => ['nullable', 'string'],
            'shop_phone' => ['nullable', 'string'], 'currency' => ['nullable', 'string', 'max:20'], 'payment_methods' => ['nullable', 'string'],
            'receipt_footer' => ['nullable', 'string'], 'receipt_show_customer' => ['nullable'], 'receipt_show_payment_method' => ['nullable'],
            'receipt_show_price_type' => ['nullable'], 'receipt_paper_size' => ['required', 'in:50mm,58mm,80mm,85mm,a4'],
            'receipt_header_alignment' => ['required', 'in:left,center,right'], 'receipt_margin_left' => ['numeric', 'between:0,30'],
            'receipt_margin_right' => ['numeric', 'between:0,30'], 'receipt_header_font_size' => ['numeric', 'between:7,24'],
            'receipt_body_font_size' => ['numeric', 'between:6,18'], 'receipt_line_height' => ['numeric', 'between:1,2.2'],
            'printer_name' => ['nullable', 'string'], 'language' => ['required', 'in:en,my'], 'admin_pin' => ['nullable', 'string']]);
        $pin = (string) ($data['admin_pin'] ?? '');
        unset($data['admin_pin']);
        DB::transaction(function () use ($data, $pin) {
            foreach ($data as $key => $value) {
                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => (string) ($value ?? '')]);
            }
            if ($pin !== '') {
                DB::table('settings')->updateOrInsert(['key' => 'admin_pin_hash'], ['value' => password_hash($pin, PASSWORD_DEFAULT)]);
            }
        });

        return $this->index();
    }

    public function printers(): array
    {
        return ['items' => []];
    }

    public function receiptPreview(Request $request): array
    {
        $settings = array_merge($this->index(), $request->all());
        $html = '<div style="font-family:sans-serif;text-align:'.htmlspecialchars($settings['receipt_header_alignment']).'">'
            .'<h2>'.htmlspecialchars($settings['shop_name']).'</h2><p>'.htmlspecialchars($settings['shop_address']).'</p><hr>'
            .'<p>Sample Product &times; 1 <strong>1,000 '.htmlspecialchars($settings['currency']).'</strong></p><hr><strong>Total: 1,000 '.htmlspecialchars($settings['currency']).'</strong></div>';

        return ['html' => $html, 'text' => $settings['shop_name']."\nSample Product x 1  1,000\nTotal: 1,000 ".$settings['currency'],
            'paper_size' => $settings['receipt_paper_size'], 'layout' => $this->layout($settings)];
    }

    public function testPrint(Request $request): array
    {
        return $this->receiptPreview($request) + ['ok' => false, 'message' => 'Laravel web mode uses the browser print dialog.'];
    }

    private function layout(array $settings): array
    {
        return ['paper_size' => $settings['receipt_paper_size'], 'header_alignment' => $settings['receipt_header_alignment'],
            'margin_left' => (float) $settings['receipt_margin_left'], 'margin_right' => (float) $settings['receipt_margin_right'],
            'header_font_size' => (float) $settings['receipt_header_font_size'], 'body_font_size' => (float) $settings['receipt_body_font_size'], 'line_height' => (float) $settings['receipt_line_height']];
    }
}
