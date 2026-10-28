<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Skills\OwnerOnboarding;
use App\Services\AiAssistantAccess;
use App\Services\AiBusinessReader;
use App\Services\AiPageLinks;
use App\Services\AiPriceChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Each instance exposes one named, schema-bounded business capability. */
class BusinessReadTool implements Tool
{
    public const DESCRIPTIONS = [
        'get_setup_progress' => 'Read the current owner setup checklist from saved business identity, payment method selection, active products, inventory and first-sale evidence. Enabled modules only. Distinguish review suggestions from required gaps. No arguments; no writes.',
        'check_business_readiness' => 'Read missing setup and review warnings for this owner business. Not a complete audit or a guarantee of readiness. No arguments; no writes. Use focused pricing tools for price-rule checks.',
        'get_feature_guide' => 'Read an MKPOS workflow skill. Optional topic: business_setup, first_products, first_purchase, first_sale, sales_ai (Sale AI/customer-chat representative; not Sell POS), credit_accounts, daily_closing or troubleshooting. Steps and safe screen button supplied. No writes.',
        'get_next_setup_step' => 'Read one suggested next step using actual current business setup. No arguments; no writes. Do not create practice transactions.',
        'open_app_screen' => 'Return a permission-checked button for the requested MKPOS page or form. Choose a screen enum: top-level modules, products_new, customers_new, suppliers_new, purchases_new, expenses_new, or settings_account (own profile/password), settings_security (owner admin PIN), or settings_shop/receipt/barcode/printer/payments/prices/language/timezone. sales_ai (shop customer-chat AI configuration) or sales_ai_topups (separate MMK credit top-ups). Defaults to settings. No data is read or saved and nothing opens until the user clicks. Include the exact returned Markdown link; do not invent destinations.',

        'get_app_settings' => 'Read allowed app settings and editable fields: shop name/title/address/phone, currency, POS payment method names, receipts, barcode printing, language and printer. No arguments. Security, credentials and staff settings are excluded. Read-only; use manage_app_settings for owner-approved changes.',
        'get_business_context' => 'Read the signed-in owner business name, currency, timezone, local date and available read capabilities. Call first for relative dates. No arguments.',
        'get_business_summary' => 'Read an overview using the same calculations as Reports: sales, expenses, estimated profit, current receivables/payables, inventory and pending shop orders. Dates default to today in business timezone. Current balances are independent of the date range.',
        'search_products' => 'Search active products by name, SKU, barcode or category. Paginated; no date filtering.',
        'get_product_details' => 'Archived products are unavailable. Read one product by required product_id, including units, cost, stock and named selling prices.',
        'get_inventory_report' => 'Read low_stock (includes out of stock), out_of_stock, all stock, or movements. Use product_id to inspect one product. Movement dates default to today. Stock is current, not historical; stock units can differ between products.',
        'search_sales' => 'Search receipts by query, customer_id or sale_id. Defaults to completed sales today; totals cover all matching receipts, not just the page. Credit amounts are original invoice credit, not current outstanding debt.',
        'search_purchases' => 'Search purchases by supplier name/query, supplier_id or purchase_id. Defaults to completed purchases created today, matching Reports date basis. Credit amounts are original invoice credit, not current outstanding debt.',
        'get_customer_account' => 'Search active customer accounts by query or customer_id, optionally ranked by highest balance; positive balance means customer owes the business. A selected customer includes paginated sales and payments for the date range, and a current all-time balance.',
        'get_supplier_account' => 'Search active suppliers by query or supplier_id, optionally ranked by highest balance; positive balance means business owes supplier. A selected supplier includes paginated purchases and payments for the date range, and a current all-time balance.',
        'get_expense_report' => 'Read expenses and totals by expense_date, with query/category filters. Defaults to completed expenses today. Totals cover all matches.',
        'get_business_report' => 'Read Reports-screen calculations for a date range, with top products, payment methods, cash movement and estimated profit. Defaults to today. Call again with a second explicit date range to compare periods. Current account balances and inventory are not historical.',
        'get_shop_orders' => 'Search own online shop orders by query, order_id, status or payment_status. Defaults to orders created today. Read selected order items without tracking credentials or payment proof. Never change an order.',
        'check_product_prices' => 'Check saved product selling prices for missing named prices, below-cost or zero prices, automatic rule mismatches and primary-price conflicts. All cost/price comparisons use current base units. Manual overrides and below-cost prices are not automatically mistakes. Read-only; returns paginated evidence and counts for a bounded product scan.',
        'check_pricing_rules' => 'Read configured price types, markup, minimum profit and rounding rules without creating defaults. Detect invalid/conflicting settings. Missing saved rules use MKPOS default manual pricing, not a setup error. Read-only; use price_type or rules.next_offset for more rules.',
        'get_pricing_health_report' => 'Check current product prices and pricing configuration together. Return errors versus owner-review warnings, current/expected automatic prices, evidence and next steps. Read-only. findings.next_offset paginates the same scan; scan.next_after_product_id starts another scan batch. Never report a partial scan as the whole business.',
    ];

    public function __construct(private MkposAssistant $agent, private string $toolName) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return self::DESCRIPTIONS[$this->toolName].(in_array('all_time', AiBusinessReader::fields($this->toolName), true)
            ? ' Set all_time=true without start/end to search the full history when dates are unknown.' : '');
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [
            'topic' => $schema->string()->max(100),
            'screen' => $schema->string()->enum(array_keys(AiPageLinks::pages())),
            'query' => $schema->string()->max(120),
            'price_type' => $schema->string()->max(50),
            'severity' => $schema->string()->enum(['all', 'error', 'warning']),
            'issue' => $schema->string()->enum(['all', ...AiPriceChecker::ISSUES]),
            'after_product_id' => $schema->integer()->min(0),
            'scan_limit' => $schema->integer()->min(1)->max(10000),
            'start' => $schema->string()->description('Inclusive business-local date YYYY-MM-DD.'),
            'end' => $schema->string()->description('Inclusive business-local date YYYY-MM-DD.'),
            'all_time' => $schema->boolean()->description('Full history; omit start/end when true.'),
            'limit' => $schema->integer()->min(1)->max(20),
            'offset' => $schema->integer()->min(0)->max(10000),
            'product_id' => $schema->integer()->min(1),
            'sale_id' => $schema->integer()->min(1),
            'purchase_id' => $schema->integer()->min(1),
            'customer_id' => $schema->integer()->min(1),
            'supplier_id' => $schema->integer()->min(1),
            'order_id' => $schema->integer()->min(1),
            'category' => $schema->string()->max(120),
            'sort' => $schema->string()->enum(['name', 'balance_desc']),
            'mode' => $schema->string()->enum(['low_stock', 'out_of_stock', 'all', 'movements']),
            'status' => $schema->string()->enum(AiBusinessReader::statuses($this->toolName)),
            'payment_status' => $schema->string()->enum(['unpaid', 'proof_submitted', 'paid', 'refund_pending', 'refunded']),
        ];
        if ($this->toolName === 'get_product_details') {
            $fields['product_id']->required();
        }

        return array_intersect_key($fields, array_flip(AiBusinessReader::fields($this->toolName)));
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->toolName;
        try {
            $result = app(AiBusinessReader::class)->run($this->agent->actor, $this->toolName, $request->all(), $this->agent->skillSession());
        } catch (ValidationException $e) {
            $result = ['error' => 'invalid_arguments', 'fields' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            $result = ['error' => match ($e->getStatusCode()) {
                403 => 'business_access_denied',
                404 => 'record_unavailable',
                default => 'business_read_unavailable',
            }];
            if ($e->getStatusCode() === 403) {
                $result['reason'] = $e->getMessage();
                $result['access'] = app(AiAssistantAccess::class)->context($this->agent->actor);
                $result['guidance'] = 'Explain the current role/module limitation using these facts. Offer general help and refer permission changes to the business owner. Do not reveal private business figures.';
            }
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'business_read_unavailable', 'message' => 'Business data could not be read. Retry or open the relevant MKPOS screen.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
