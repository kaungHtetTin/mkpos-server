<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Services\AiBusinessActions;
use App\Services\AiBusinessReader;
use App\Services\AiManagementOperations;
use App\Services\AiManagementReader;
use App\Services\AiPosManagement;
use App\Services\AiPriceRuleActions;
use App\Services\AiSalesAiManagement;
use App\Services\ShopContacts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BusinessManagementTool implements Tool
{
    public const NAMES = ['get_price_type_rules', 'get_business_management_records', ...AiSalesAiManagement::READS, ...AiManagementOperations::OPERATIONS];

    public function __construct(private MkposAssistant $agent, private string $tool) {}

    public function name(): string
    {
        return $this->tool;
    }

    public function description(): string
    {
        $text = match ($this->tool) {
            'get_sales_ai_telegram_settings' => 'Read saved own business Telegram bot reply settings, limits, public bot username, registration flag and token-configured flag. No tokens/secrets or customer histories. Not a live connectivity check.',
            'get_sales_ai_telegram_groups' => 'Read bounded own current-bot groups, exact record IDs, approval, membership, mention/reply triggers and limits. Use pagination or record_id for exact group; do not invent IDs.',
            'manage_sales_ai_telegram_settings' => 'Prepare owner-reviewed partial Telegram enabled/groups_enabled/daily_limit/per_user_limit changes. Read Telegram settings first. No token, webhook URL, registration, account-link or conversation changes. Token entry/test/register use the sales_ai_telegram page.',
            'manage_sales_ai_telegram_groups' => 'Prepare one owner-reviewed group create or partial update. Read exact record_id first for update; create needs an owner-provided negative Telegram chat_id and title. Supports approval/status, mention/reply triggers and daily/per-user limits. Never invent IDs or approve every group. Does not add the bot to Telegram or alter membership. No delete or immutable chat-ID changes.',
            'get_sales_ai_settings' => 'Read this owner business Sales AI enabled state, name, instructions, soul, tool permissions, published policies, version, MMK wallet and platform readiness flags. No credentials or writes.',
            'get_sales_ai_activity' => 'Read bounded own Sales AI wallet entries, top-ups, conversations with exact UUID/mode/version, or response runs. resource=usage returns the actual 30-day/12-month MMK spending series in business timezone (omit other arguments). Paginate other records honestly. No private evidence paths, provider credentials or another business records.',
            'manage_sales_ai_settings' => 'Prepare an owner-reviewed partial Sales AI enabled/name/instructions/soul update. Read current settings first. Preserves tool permissions and policies. Never claim saved until the owner confirms the review card.',
            'manage_sales_ai_tools' => 'Prepare owner-reviewed Sales AI tool permissions changes with data.enable/data.disable name arrays. Preserves unspecified permissions. request_human is mandatory and cannot be disabled.',
            'manage_sales_ai_policies' => 'Prepare owner-reviewed published delivery/payment/cancellation/returns/warranty text updates. Preserves unspecified policies; empty text unpublishes the specified policy and requires human clarification.',
            'manage_sales_ai_handover' => 'Prepare owner-reviewed AI/human handling for scope=conversation with exact owned thread_uuid, or explicit scope=all. mode=ai or human. Read conversations first. Returning to AI can generate normally billed replies; never assume a request for one conversation means all.',
            'manage_sales_ai_topups' => 'Prepare owner-reviewed cancellation of one pending Sales AI top-up by exact record_id. Cannot create/approve/reject/credit/refund top-ups or change platform rates. For adding credit, give the Sales AI top-up page button and use its native validated screenshot form.',
            'get_price_type_rules' => 'Read current owner configured named price types and rules. No writes.',
            'get_business_management_records' => 'Read bounded owned records and exact IDs before preparing management actions. Includes inactive customers/suppliers but always excludes archived products (including exact IDs); also includes transaction details, shop configuration/receiving entries, publications, orders/requests, promotions, customer keys, reviews and management audit references. No arbitrary tables or security credentials. No writes.',
            'manage_price_types' => 'Prepare price-type create/update/delete. Use automatic cost markup for wholesale/retail percentages, not profit margin. Read exact current names first. apply_to_existing defaults false and preserves manual overrides. Preview every affected product price. Supported: never refuse price-rule settings as unavailable.',
            'manage_sales' => 'Prepare create/update/delete of a real POS sale. Require actual items, prices, payment method and paid_amount; do not invent a sale or assume payment. Updates preserve unspecified header/line fields. Preview stock and original credit effects. Existing PIN checks remain in the owner form. Online-order sales cannot be edited through POS.',
            'manage_purchases' => 'Prepare create/update/delete of a real completed purchase. Require matched products, configured units, quantities, costs and explicit paid_amount/method. It receives stock when confirmed, not a pending purchase order. Deleting reverses stock and original credit. Existing PIN checks remain in the owner form.',
            'manage_account_payments' => 'Prepare create/update/delete of a customer or supplier account payment. party is customer/supplier; create uses party_id; update/delete uses payment record_id. Explicit amount/direction/method required, never infer funds from a screenshot. Account settlement does not rewrite original invoice credit.',
            'manage_inventory' => 'Prepare a manual stock delta in product base units. action=adjust, record_id is product ID, data.quantity is signed change, data.reason required. Preserves online reservations and shows before/after stock. Never infer counted stock or create an adjustment merely from a warning.',
            'manage_business_records' => 'Prepare archive of an active product, archive/restore of a customer/supplier, or deletion of an expense. Archived products are ignored and cannot be restored or changed by Wyne Wyne. Existing history is preserved for archived contacts/products; expense deletion uses native PIN protection. Use existing create/update tools for editing records.',
            'manage_business_timezone' => 'Prepare owner-reviewed business timezone change. Use an actual supported IANA timezone. Does not rewrite historical timestamps or change staff/security settings.',
            'manage_shop_settings' => 'Prepare partial storefront/marketplace/fulfillment/opening-hour settings updates. Preserves unspecified fields, receiving methods and delivery zones. Changing shop URL after orders is prohibited. Requires the owner real saved location; never invent GPS consent.',
            'manage_shop_contacts' => 'Prepare create/update/delete of a public shop contact (platform, optional label, value). Existing contacts use record_id. Follow native contact format validation.',
            'manage_shop_delivery_zones' => 'Prepare delivery-zone create/update/delete. Existing zones use data.entry_id from the read tool; data.name and fee are required for create/update.',
            'manage_shop_payment_methods' => 'Prepare public shop receiving-method create/update/delete. Existing methods use data.entry_id. Manual cash/transfer only; no gateway secrets. Keep at least one active payment method.',
            'manage_shop_catalog' => 'Prepare exact product publication/unit/description changes in data.items. Each product_id is owned. Preserves product inventory/prices; valid online named price and configured buying unit are checked.',
            'manage_shop_orders' => 'Prepare a controlled order transition, reopen, request resolution, payment confirmation/rejection or refund record. Read order details first. Actual payment and physical-return confirmations are supplied only in the owner review form, never model arguments. Does not transfer funds. Native order/stock/refund state rules remain mandatory.',
            'manage_shop_promotions' => 'Prepare create/update/delete of an online flash sale or coupon. Use explicit discount, schedule and limits; flash sale needs a matched product, coupon uses a code. Respects native overlap, usage and publication rules.',
            'manage_shop_customers' => 'Prepare block/unblock or explicit POS credit-contact identity linking for a returned customer_key. Linking requires contact_id OR create_new=true; never creates a debt or merges account balances.',
            'manage_shop_reviews' => 'Prepare a public reply to an owned shop review. Read the original review first; exact reply is shown to the owner before publication.',
        };

        return $text.(str_starts_with($this->tool, 'manage_') ? ' PREPARE ONLY: nothing changes until the signed-in owner reviews all exact values and confirms in the app. Chat yes, tool booleans and model arguments never grant approval. Never collect PINs in chat.' : '');
    }

    public function schema(JsonSchema $s): array
    {
        if (in_array($this->tool, [...AiSalesAiManagement::READS, ...AiSalesAiManagement::OPERATIONS], true)) return $this->salesAiSchema($s);
        if ($this->tool === 'get_price_type_rules') {
            return [];
        }
        if ($this->tool === 'get_business_management_records') {
            return ['resource' => $s->string()->enum(AiManagementReader::RESOURCES)->required(), 'record_id' => $s->integer()->min(1), 'customer_key' => $s->string()->max(150), 'query' => $s->string()->max(120), 'limit' => $s->integer()->min(1)->max(20), 'offset' => $s->integer()->min(0)->max(1000000)];
        }
        if ($this->tool === 'manage_price_types') {
            return ['changes' => $s->array()->min(1)->max(20)->items($s->object(['action' => $s->string()->enum(['create', 'update', 'delete'])->required(), 'name' => $s->string()->max(50)->required(), 'new_name' => $s->string()->max(50), 'pricing_mode' => $s->string()->enum(['manual', 'automatic']), 'markup_percent' => $s->number()->min(0)->max(1000), 'rounding' => $s->integer()->min(1)->max(100000), 'minimum_profit' => $s->integer()->min(0), 'apply_to_existing' => $s->boolean()]))->required()];
        }
        if ($this->tool === 'manage_business_timezone') {
            return ['timezone' => $s->string()->max(100)->required()];
        }
        $actions = match ($this->tool) {
            'manage_inventory' => ['adjust'],'manage_business_records' => ['archive', 'restore', 'delete'],'manage_shop_settings','manage_shop_catalog' => ['update'],'manage_shop_orders' => ['transition', 'reopen', 'resolve_request', 'confirm_payment', 'reject_payment', 'record_refund'],'manage_shop_customers' => ['block', 'unblock', 'link_credit'],'manage_shop_reviews' => ['reply'],default => ['create', 'update', 'delete']
        };
        $out = ['action' => $s->string()->enum($actions)->required(), 'record_id' => $s->integer()->min(1)];
        if ($this->tool === 'manage_account_payments') {
            $out += ['party' => $s->string()->enum(['customer', 'supplier'])->required(), 'party_id' => $s->integer()->min(1)];
        }
        if ($this->tool === 'manage_business_records') {
            $out['type'] = $s->string()->enum(['product', 'customer', 'supplier', 'expense'])->required();
        }
        if ($this->tool === 'manage_shop_customers') {
            $out['customer_key'] = $s->string()->max(150)->required();
        }
        $out['data'] = $s->object(in_array($this->tool, AiPosManagement::OPERATIONS, true) ? $this->posData($s) : $this->shopData($s));

        return $out;
    }

    private function salesAiSchema(JsonSchema $s): array
    {
        $names = fn () => $s->array()->max(20)->items($s->string()->enum(array_keys(\App\Services\SalesAi\Settings::TOOLS)));
        return match ($this->tool) {
            'get_sales_ai_telegram_settings' => [],
            'get_sales_ai_telegram_groups' => ['record_id' => $s->integer()->min(1), 'query' => $s->string()->max(120), 'limit' => $s->integer()->min(1)->max(20), 'offset' => $s->integer()->min(0)->max(1000000)],
            'manage_sales_ai_telegram_settings' => ['data' => $s->object(['enabled' => $s->boolean(), 'groups_enabled' => $s->boolean(), 'daily_limit' => $s->integer()->min(1)->max(10000), 'per_user_limit' => $s->integer()->min(1)->max(500)])->required()],
            'manage_sales_ai_telegram_groups' => ['action' => $s->string()->enum(['create', 'update'])->required(), 'record_id' => $s->integer()->min(1), 'data' => $s->object(['chat_id' => $s->integer()->min(-4503599627370495)->max(-1), 'title' => $s->string()->max(255), 'status' => $s->string()->enum(['pending', 'approved', 'disabled']), 'reply_mentions' => $s->boolean(), 'reply_to_bot' => $s->boolean(), 'daily_limit' => $s->integer()->min(1)->max(10000), 'per_user_limit' => $s->integer()->min(1)->max(500)])->required()],
            'get_sales_ai_settings' => [],
            'get_sales_ai_activity' => ['resource' => $s->string()->enum(['wallet_entries', 'topups', 'conversations', 'runs', 'usage'])->required(), 'thread_uuid' => $s->string()->format('uuid'), 'record_id' => $s->integer()->min(1), 'limit' => $s->integer()->min(1)->max(20), 'offset' => $s->integer()->min(0)->max(1000000)],
            'manage_sales_ai_settings' => ['data' => $s->object(['enabled' => $s->boolean(), 'name' => $s->string()->max(80), 'instructions' => $s->string()->max(4000), 'soul' => $s->string()->max(2000)])->required()],
            'manage_sales_ai_tools' => ['data' => $s->object(['enable' => $names(), 'disable' => $names()])->required()],
            'manage_sales_ai_policies' => ['data' => $s->object(array_fill_keys(\App\Services\SalesAi\Settings::POLICY_FIELDS, $s->string()->max(2000)))->required()],
            'manage_sales_ai_handover' => ['scope' => $s->string()->enum(['conversation', 'all'])->required(), 'thread_uuid' => $s->string()->format('uuid'), 'mode' => $s->string()->enum(['ai', 'human'])->required()],
            'manage_sales_ai_topups' => ['action' => $s->string()->enum(['cancel'])->required(), 'record_id' => $s->integer()->min(1)->required()],
        };
    }

    private function posData(JsonSchema $s): array
    {
        $common = ['payment_method' => $s->string()->max(255), 'paid_amount' => $s->integer()->min(0)->max(1000000000000), 'note' => $s->string()->max(2000)];

        return match ($this->tool) {
            'manage_sales' => ['customer_id' => $s->integer()->min(1), 'discount' => $s->integer()->min(0), 'payment_type' => $s->string()->enum(['cash', 'credit']), ...$common, 'items' => $s->array()->min(1)->max(100)->items($s->object(['product_id' => $s->integer()->min(1)->required(), 'quantity' => $s->number()->min(0)->max(1000000)->required(), 'foc_quantity' => $s->number()->min(0)->max(1000000), 'unit_price' => $s->integer()->min(0)->max(1000000000)->required(), 'price_type' => $s->string()->max(50)]))],
            'manage_purchases' => ['supplier_id' => $s->integer()->min(1), 'supplier_name' => $s->string()->max(255), 'reference_no' => $s->string()->max(255), 'purchase_date' => $s->string()->description('Business-local YYYY-MM-DD.'), 'expected_delivery' => $s->string()->description('Business-local YYYY-MM-DD.'), ...$common, 'items' => $s->array()->min(1)->max(100)->items($s->object(['id' => $s->integer()->min(1), 'product_id' => $s->integer()->min(1)->required(), 'unit_name' => $s->string()->max(50), 'quantity' => $s->number()->min(.001)->max(1000000)->required(), 'foc_quantity' => $s->number()->min(0)->max(1000000), 'unit_cost' => $s->integer()->min(0)->max(1000000000)->required()]))],
            'manage_inventory' => ['quantity' => $s->number()->min(-1000000)->max(1000000)->required(), 'reason' => $s->string()->max(2000)->required()],
            'manage_account_payments' => ['amount' => $s->integer()->min(1)->max(1000000000000), 'direction' => $s->string()->enum(['customer_to_shop', 'shop_to_customer', 'shop_to_supplier', 'supplier_to_shop']), 'payment_method' => $s->string()->max(255), 'note' => $s->string()->max(2000)],
            default => [],
        };
    }

    private function shopData(JsonSchema $s): array
    {
        $fields = [
            'entry_id' => $s->string()->max(50), 'name' => $s->string()->max(255), 'fee' => $s->integer()->min(0)->max(1000000000), 'platform' => $s->string()->enum(ShopContacts::PLATFORMS), 'label' => $s->string()->max(100), 'value' => $s->string()->max(2000),
            'type' => $s->string()->enum(['cash', 'transfer']), 'instructions' => $s->string()->max(2000), 'bank' => $s->string()->max(100), 'account_name' => $s->string()->max(255), 'account_no' => $s->string()->max(100), 'is_active' => $s->boolean(), 'provider' => $s->string()->enum(['manual']),
            'slug' => $s->string()->max(100), 'display_name' => $s->string()->max(255), 'description' => $s->string()->max(4000), 'phone' => $s->string()->max(255), 'address' => $s->string()->max(2000), 'currency' => $s->string()->max(20), 'storefront_enabled' => $s->boolean(), 'accepting_orders' => $s->boolean(), 'marketplace_enabled' => $s->boolean(), 'price_type' => $s->string()->max(50), 'pickup_instructions' => $s->string()->max(2000), 'pickup_address' => $s->string()->max(255), 'pickup_hours' => $s->string()->max(255), 'announcement' => $s->string()->max(1000), 'closed_until' => $s->string(), 'opening_hours' => $s->array()->max(7)->items($s->object(['closed' => $s->boolean()->required(), 'opens' => $s->string()->required(), 'closes' => $s->string()->required()])),
            'reason' => $s->string()->max(2000), 'amount' => $s->integer()->min(0)->max(1000000000000), 'reference' => $s->string()->max(255), 'review_note' => $s->string()->max(2000), 'request_id' => $s->integer()->min(1), 'staff_note' => $s->string()->max(2000), 'status' => $s->string()->enum(['accepted', 'packing', 'ready_for_pickup', 'dispatched', 'return_pending', 'completed', 'cancelled', 'approved', 'rejected', 'resolved']),
            'reply' => $s->string()->max(2000), 'contact_id' => $s->integer()->min(1), 'create_new' => $s->boolean(),
            'items' => $s->array()->min(1)->max(100)->items($s->object(['product_id' => $s->integer()->min(1)->required(), 'published' => $s->boolean()->required(), 'unit' => $s->string()->enum(['base', 'purchase'])->required(), 'description' => $s->string()->max(4000)])),
            'code' => $s->string()->max(40), 'product_id' => $s->integer()->min(1), 'discount_type' => $s->string()->enum(['percentage', 'fixed']), 'discount_value' => $s->integer()->min(1)->max(1000000000), 'minimum_subtotal' => $s->integer()->min(0)->max(1000000000), 'usage_limit' => $s->integer()->min(1)->max(1000000), 'quantity_limit' => $s->number()->min(.001)->max(1000000), 'starts_at' => $s->string()->description('Business-local YYYY-MM-DDTHH:mm.'), 'ends_at' => $s->string()->description('Business-local YYYY-MM-DDTHH:mm.'), 'enabled' => $s->boolean(),
        ];
        $keys = match ($this->tool) {
            'manage_shop_settings' => ['slug', 'display_name', 'description', 'phone', 'address', 'currency', 'storefront_enabled', 'accepting_orders', 'marketplace_enabled', 'price_type', 'pickup_instructions', 'pickup_address', 'pickup_hours', 'announcement', 'closed_until', 'opening_hours'],
            'manage_shop_contacts' => ['platform', 'label', 'value'],
            'manage_shop_delivery_zones' => ['entry_id', 'name', 'fee'],
            'manage_shop_payment_methods' => ['entry_id', 'name', 'type', 'instructions', 'bank', 'account_name', 'account_no', 'is_active', 'provider'],
            'manage_shop_catalog' => ['items'],
            'manage_shop_orders' => ['status', 'reason', 'amount', 'reference', 'review_note', 'request_id', 'staff_note'],
            'manage_shop_promotions' => ['type', 'name', 'code', 'product_id', 'discount_type', 'discount_value', 'minimum_subtotal', 'usage_limit', 'quantity_limit', 'starts_at', 'ends_at', 'enabled'],
            'manage_shop_customers' => ['reason', 'contact_id', 'create_new'],
            'manage_shop_reviews' => ['reply'],
        };
        if ($this->tool === 'manage_shop_promotions') {
            $fields['type'] = $s->string()->enum(['flash_sale', 'coupon']);
        }

        return array_intersect_key($fields, array_flip($keys));
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->tool;
        try {
            app(AiBusinessReader::class)->run($this->agent->actor, 'get_business_context', []);
            if (in_array($this->tool, AiSalesAiManagement::READS, true)) {
                $result = app(AiSalesAiManagement::class)->read($this->tool, $request->all());
            } elseif ($this->tool === 'get_price_type_rules') {
                abort_if($request->all(), 422, 'This read tool accepts no arguments.');
                abort_unless(in_array('products', config('mkpos.enabled_modules', []), true), 403, 'Products module is unavailable.');
                $result = app(AiPriceRuleActions::class)->read();
            } elseif ($this->tool === 'get_business_management_records') {
                $result = app(AiManagementReader::class)->read($request->all());
            } else {
                abort_unless($this->agent->conversationId && $this->agent->messageId, 422, 'An authenticated conversation is required.');
                $result = app(AiBusinessActions::class)->prepare($this->agent->actor, $this->agent->conversationId, $this->agent->messageId, $this->tool, $request->all());
            }
        } catch (ValidationException $e) {
            $result = ['error' => 'invalid_arguments', 'fields' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            $result = ['error' => 'business_management_unavailable', 'status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'business_management_unavailable', 'message' => 'The management request could not complete. No change was saved.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
