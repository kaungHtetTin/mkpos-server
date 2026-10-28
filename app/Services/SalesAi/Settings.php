<?php

namespace App\Services\SalesAi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class Settings
{
    public const TOOLS = [
        'search_products' => 'Search published products, selling prices, stock availability and current promotions.',
        'shop_information' => 'Read public shop contact, opening hours, delivery and payment instructions.',
        'customer_orders' => 'Look up orders owned by the customer in this conversation only.',
        'get_product_details' => 'Read a published product with photos, current selling units, prices, promotions and availability.',
        'compare_products' => 'Compare up to five published products using verified public facts.',
        'recommend_products' => 'Find published products matching customer needs, category and budget; never invent suitability claims.',
        'find_product_from_image' => 'Find candidate catalog matches using visible text in the current customer image. Matches require customer confirmation.',
        'get_sales_policies' => 'Read only the owner-published delivery, payment, cancellation, return and warranty policies.',
        'calculate_quote' => 'Calculate an exact current server quote including promotions, coupon and selected delivery/payment options. Never invent missing choices.',
        'manage_customer_cart' => 'Read the customer cart or prepare reviewed product/quantity changes. Customer must press Apply cart; no stock is reserved.',
        'get_delivery_options' => 'Read real delivery zones, pickup instructions and active receiving methods with exact IDs. Courier-paid charges may be unknown.',
        'prepare_checkout' => 'Prepare a customer-confirmed checkout form. Non-COD methods require a payment screenshot before confirmation; COD needs none. Customer reviews items, receiving details, total and image. Payment awaits shop verification.',
        'submit_confirmed_order' => 'Read the status of a prepared checkout action. Cannot confirm or place an order from model arguments or chat consent.',
        'submit_order_payment_proof' => 'Prepare a customer-confirmed proof attachment to their own shop order. Does not verify funds, approve payment, top up Sales AI or pay MKPOS subscriptions.',
        'get_customer_order_details' => 'Read items, fulfillment, payment and customer-visible requests for one order owned by this customer and shop.',
        'request_order_change' => 'Prepare a customer-confirmed change request for owner review. Never silently edit quantities, prices or delivery details on an existing order.',
        'request_cancellation' => 'Prepare a customer-confirmed cancellation request using native rules. Unpaid pending orders may cancel immediately; other cancellations require owner review.',
        'request_return' => 'Prepare a customer-confirmed return request for a paid completed order. Owner handles review, goods return, sale reversal and refund.',
        'get_customer_request_status' => 'Read the customer-visible status and shop response to this customer order requests.',
        'prepare_buy_again' => 'Prepare a reviewed cart from a previous owned order using current units, stock and prices; show skipped or reduced items.',
    ];

    public const POLICY_FIELDS = ['delivery', 'payment', 'cancellation', 'returns', 'warranty'];

    public static function rules(): array
    {
        return ['enabled' => ['required', 'boolean'], 'name' => ['required', 'string', 'max:80', 'regex:/\S/u'], 'instructions' => ['required', 'string', 'max:4000', 'regex:/\S/u'], 'soul' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'tool_permissions' => ['present', 'array', 'max:'.count(self::TOOLS)], 'tool_permissions.*' => ['string', 'distinct', Rule::in(array_keys(self::TOOLS))], 'sales_policies' => ['sometimes', 'array:'.implode(',', self::POLICY_FIELDS)], 'sales_policies.*' => ['nullable', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:1']];
    }

    public function ready(): bool
    {
        return Schema::hasTable('sales_ai_settings');
    }

    public function get(int $businessId): array
    {
        $row = $this->ready() ? DB::table('sales_ai_settings')->where('business_id', $businessId)->first() : null;
        return ['enabled' => (bool) ($row->enabled ?? false), 'name' => $row->name ?? 'Sales assistant',
            'instructions' => $row->instructions ?? 'Help customers choose suitable products. Use verified shop information and ask the owner when uncertain.',
            'soul' => $row->soul ?? \App\Services\AgentSkills\SkillCatalog::text('sales-default-soul'),
            'tool_permissions' => $row ? json_decode($row->tool_permissions, true) : array_keys(self::TOOLS), 'sales_policies' => isset($row->sales_policies) ? (json_decode($row->sales_policies, true) ?: []) : [], 'version' => (int) ($row->version ?? 1)];
    }

    public function update(int $businessId, array $data): array
    {
        return DB::transaction(function () use ($businessId, $data) {
            abort_unless(DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(), 404, 'Business unavailable.');
            $current = $this->get($businessId);
            abort_unless($current['version'] === $data['version'], 409, 'Sales AI settings changed. Reload before saving.');
            DB::table('sales_ai_settings')->updateOrInsert(['business_id' => $businessId], [
                'enabled' => $data['enabled'], 'name' => trim($data['name']), 'instructions' => trim($data['instructions']), 'soul' => trim($data['soul']),
                'tool_permissions' => json_encode(array_values(array_unique($data['tool_permissions']))), 'version' => $current['version'] + 1,
                ...(Schema::hasColumn('sales_ai_settings', 'sales_policies') ? ['sales_policies' => json_encode($data['sales_policies'] ?? $current['sales_policies'])] : []),
                'created_at' => DB::table('sales_ai_settings')->where('business_id', $businessId)->value('created_at') ?? now('UTC'), 'updated_at' => now('UTC')]);
            return $this->get($businessId);
        });
    }
}
