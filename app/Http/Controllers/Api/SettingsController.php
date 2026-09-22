<?php

namespace App\Http\Controllers\Api;

use App\Support\BusinessLogo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends ApiController
{
    private const STAFF_WRITABLE_KEYS = [
        'shop_title', 'receipt_footer', 'receipt_show_customer', 'receipt_show_payment_method',
        'receipt_show_price_type', 'receipt_paper_size', 'receipt_header_alignment',
        'receipt_margin_left', 'receipt_margin_right', 'receipt_header_font_size',
        'receipt_body_font_size', 'receipt_line_height', 'printer_name',
        'barcode_paper_size', 'barcode_columns', 'barcode_label_height_mm',
        'barcode_label_format', 'barcode_page_width_mm', 'barcode_page_height_mm',
        'barcode_label_width_mm', 'barcode_margin_x_mm', 'barcode_margin_y_mm',
        'barcode_gap_x_mm', 'barcode_gap_y_mm',
        'barcode_show_name', 'barcode_show_value',
    ];

    private const DEFAULTS = [
        'shop_name' => 'MKPOS Shop', 'shop_title' => '', 'shop_address' => '', 'shop_phone' => '', 'currency' => 'Ks',
        'payment_methods' => 'Cash,Wallet Pay,Banking Pay,KPay,Wave Pay,Credit', 'price_types' => 'Retail', 'receipt_footer' => '',
        'receipt_show_customer' => '1', 'receipt_show_payment_method' => '1', 'receipt_show_price_type' => '1',
        'receipt_paper_size' => '80mm', 'receipt_header_alignment' => 'center', 'receipt_margin_left' => '3', 'receipt_margin_right' => '3',
        'receipt_header_font_size' => '11', 'receipt_body_font_size' => '8.2', 'receipt_line_height' => '1.45',
        'printer_name' => '', 'language' => 'en',
        'barcode_paper_size' => 'a4', 'barcode_columns' => '3', 'barcode_label_height_mm' => '28',
        'barcode_label_format' => 'sheet_a4_3x8', 'barcode_page_width_mm' => '210', 'barcode_page_height_mm' => '297',
        'barcode_label_width_mm' => '63.5', 'barcode_margin_x_mm' => '7.25', 'barcode_margin_y_mm' => '12.9',
        'barcode_gap_x_mm' => '2.5', 'barcode_gap_y_mm' => '0',
        'barcode_show_name' => '1', 'barcode_show_value' => '1',
    ];

    public function index(): array
    {
        $stored = DB::table('settings')->where('key', '<>', 'admin_pin_hash')->pluck('value', 'key')->all();
        $settings = BusinessLogo::addToSettings(array_merge(self::DEFAULTS, $stored));
        $pinHash = (string) (DB::table('settings')->where('key', 'admin_pin_hash')->value('value') ?? '');
        $settings['admin_pin_set'] = trim($pinHash) !== '' ? '1' : '0';

        return $settings;
    }

    public function update(Request $request): array
    {
        $data = $request->validate(['shop_name' => ['required', 'string', 'max:255'], 'shop_title' => ['nullable', 'string'], 'shop_address' => ['nullable', 'string'],
            'shop_phone' => ['nullable', 'string'], 'currency' => ['nullable', 'string', 'max:20'], 'payment_methods' => ['nullable', 'string'],
            'receipt_footer' => ['nullable', 'string'], 'receipt_show_customer' => ['nullable'], 'receipt_show_payment_method' => ['nullable'],
            'receipt_show_price_type' => ['nullable'], 'receipt_paper_size' => ['sometimes', 'required', 'in:50mm,58mm,80mm,85mm,a5,a4'],
            'receipt_header_alignment' => ['sometimes', 'required', 'in:left,center,right'], 'receipt_margin_left' => ['numeric', 'between:0,30'],
            'receipt_margin_right' => ['numeric', 'between:0,30'], 'receipt_header_font_size' => ['numeric', 'between:7,24'],
            'receipt_body_font_size' => ['numeric', 'between:6,18'], 'receipt_line_height' => ['numeric', 'between:1,2.2'],
            'barcode_paper_size' => ['sometimes', 'required', 'in:a4,a5'], 'barcode_columns' => ['sometimes', 'required', 'integer', 'between:1,4'],
            'barcode_label_height_mm' => ['sometimes', 'required', 'numeric', 'between:18,120'], 'barcode_show_name' => ['nullable'],
            'barcode_label_format' => ['sometimes', 'required', 'in:sheet_a4_3x8,sheet_a4_4x10,roll_40x30,roll_50x30,roll_60x40,custom_sheet,custom_roll'],
            'barcode_page_width_mm' => ['sometimes', 'required', 'numeric', 'between:20,400'],
            'barcode_page_height_mm' => ['sometimes', 'required', 'numeric', 'between:20,600'],
            'barcode_label_width_mm' => ['sometimes', 'required', 'numeric', 'between:15,200'],
            'barcode_margin_x_mm' => ['sometimes', 'required', 'numeric', 'between:0,30'],
            'barcode_margin_y_mm' => ['sometimes', 'required', 'numeric', 'between:0,30'],
            'barcode_gap_x_mm' => ['sometimes', 'required', 'numeric', 'between:0,20'],
            'barcode_gap_y_mm' => ['sometimes', 'required', 'numeric', 'between:0,20'],
            'barcode_show_value' => ['nullable'], 'printer_name' => ['nullable', 'string'], 'language' => ['required', 'in:en,my'], 'admin_pin' => ['nullable', 'string']]);
        $pin = (string) ($data['admin_pin'] ?? '');
        unset($data['admin_pin']);
        $isOwner = $request->user('web')?->role === 'owner';
        if (! $isOwner) {
            $data = array_intersect_key($data, array_flip(self::STAFF_WRITABLE_KEYS));
            $pin = '';
        }
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

    public function uploadLogo(Request $request): array
    {
        $data = $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:512', 'dimensions:max_width=2400,max_height=2400'],
        ]);
        $businessId = (int) $request->user('web')->business_id;
        $previous = (string) (DB::table('settings')->where('key', BusinessLogo::SETTING_KEY)->value('value') ?? '');
        $path = $data['logo']->store('business-logos/'.$businessId, 'local');

        try {
            DB::table('settings')->updateOrInsert(
                ['key' => BusinessLogo::SETTING_KEY],
                ['value' => $path]
            );
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        if ($previous !== '' && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return $this->index();
    }

    public function deleteLogo(): array
    {
        $path = (string) (DB::table('settings')->where('key', BusinessLogo::SETTING_KEY)->value('value') ?? '');
        DB::table('settings')->where('key', BusinessLogo::SETTING_KEY)->delete();
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }

        return $this->index();
    }

    public function printers(): array
    {
        return ['items' => []];
    }

    public function receiptPreview(Request $request): array
    {
        $settings = array_merge($this->index(), $request->except(['receipt_logo_data_url', BusinessLogo::SETTING_KEY]));
        return \App\Support\ReceiptRenderer::render([
            'id' => 0, 'receipt_no' => 'TEST-PRINT', 'created_at' => now()->toDateTimeString(),
            'customer_name' => 'Sample Customer', 'payment_method' => 'Cash',
            'subtotal' => 1000, 'discount' => 0, 'total' => 1000, 'paid_amount' => 1000, 'credit_amount' => 0,
            'items' => [['product_name' => 'Sample Product — မြန်မာစာ စမ်းသပ်ရန်', 'quantity' => 1,
                'unit_price' => 1000, 'line_total' => 1000, 'foc_quantity' => 0, 'price_type' => 'Retail']],
        ], $settings);
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
