<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Services\AiAppSettings;
use App\Services\AiBusinessActions;
use App\Services\AiBusinessOperations;
use App\Services\ProductImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BusinessActionTool implements Tool
{
    public const HELPERS = ['prepare_business_action', 'get_action_preview', 'execute_confirmed_action', 'get_action_status', 'preview_product_import'];

    public function __construct(private MkposAssistant $agent, private string $toolName) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return match ($this->toolName) {
            'manage_app_settings' => 'Prepare owner-approved app setting CRUD. data.action=create/update with data.values mapping allowed setting keys to scalar values; delete/reset with data.keys restores application defaults. Read get_app_settings first for supported fields. Use shop_name to rename the business. Never refuse supported settings changes merely because older messages said unavailable. Security, credentials, staff assignments, price-type rules and storefront configuration are excluded. Does not save until owner presses Confirm changes.',
            'prepare_business_action' => 'Prepare an exact business change for owner review. Select operation and its data. Never saves business records. Prefer the named create/update tool for its narrower schema.',
            'get_action_preview' => 'Read the exact saved proposal before/after values, with pagination. It does not grant approval.',
            'get_action_status' => 'Read whether a proposal is pending, expired, cancelled or completed, and its saved result.',
            'execute_confirmed_action' => 'Retrieve the result of an action already confirmed in the MKPOS app. Pending proposals cannot execute here: no chat confirmation or model argument can approve a proposal.',
            'preview_product_import' => 'Read the current owner CSV staging summary and first issue rows. Omit import_id for the CSV attached to this chat. No products are imported.',
            'execute_product_import' => 'Prepare a proposal to import the ready rows of an owner-uploaded CSV. Required data.import_id comes from preview_product_import. Nothing is imported until the owner reviews and confirms in the app.',
            'update_product_prices' => 'Prepare up to 100 exact named price changes: data.changes [{product_id,price_type,price}], OR data.category + data.price_type + data.percentage. Preserve other prices. Overrides become manual prices. Owner confirmation required.',
            'create_product' => 'Prepare a new product (data.name and data.price required), units, barcode, cost and named prices. New products start with zero stock. Owner confirmation required.',
            'update_product' => 'Prepare changes to product details using data.record_id and changed fields. Preserves stock and existing named prices; use update_product_prices for prices. Owner confirmation required.',
            default => 'Prepare '.str_replace('_', ' ', $this->toolName).' with data fields; updates need data.record_id. Preserve omitted fields. Nothing is saved until the owner reviews and confirms in the MKPOS app.',
        };
    }

    public function schema(JsonSchema $s): array
    {
        if (in_array($this->toolName, ['get_action_preview', 'get_action_status', 'execute_confirmed_action'], true)) {
            return ['action_id' => $s->string()->required(), 'offset' => $s->integer()->min(0)->max(5000), 'limit' => $s->integer()->min(1)->max(20)];
        }
        if ($this->toolName === 'preview_product_import') {
            return ['import_id' => $s->string()];
        }
        $operation = $this->toolName;
        if ($operation === 'manage_app_settings') {
            $values = [];
            foreach (app(AiAppSettings::class)->defaults() as $key => $value) {
                $values[$key] = $s->string()->description('Proposed setting value; use 1/0 for toggles.');
            }

            return ['data' => $s->object(['action' => $s->string()->enum(['create', 'update', 'delete', 'reset'])->required(), 'values' => $s->object($values), 'keys' => $s->array()->items($s->string()->enum(array_keys($values)))])->required()];
        }
        $fields = [
            'record_id' => $s->integer()->min(1), 'name' => $s->string()->max(255), 'title' => $s->string()->max(255),
            'sku' => $s->string()->max(255), 'barcode' => $s->string()->max(255), 'category' => $s->string()->max(255),
            'base_unit' => $s->string()->max(50), 'purchase_unit' => $s->string()->max(50), 'purchase_conversion_factor' => $s->number()->min(1)->max(1000000),
            'cost' => $s->integer()->min(0), 'price' => $s->integer()->min(0), 'low_stock_threshold' => $s->number()->min(0),
            'phone' => $s->string()->max(255), 'email' => $s->string()->max(255), 'address' => $s->string()->max(2000),
            'contact_person' => $s->string()->max(255), 'note' => $s->string()->max(2000), 'amount' => $s->integer()->min(1),
            'payment_method' => $s->string()->max(255), 'expense_date' => $s->string()->description('Business-local YYYY-MM-DD.'),
            'import_id' => $s->string(), 'price_type' => $s->string()->max(50), 'percentage' => $s->number()->min(-100)->max(1000),
            'prices' => $s->array()->max(20)->items($s->object(['name' => $s->string()->required(), 'price' => $s->integer()->min(0)->required(), 'is_manual' => $s->boolean()])),
            'changes' => $s->array()->min(1)->max(100)->items($s->object(['product_id' => $s->integer()->min(1)->required(), 'price_type' => $s->string()->required(), 'price' => $s->integer()->min(0)->required()])),
        ];
        if ($operation === 'prepare_business_action') {
            $settingValues = [];
            foreach (app(AiAppSettings::class)->defaults() as $key => $value) {
                $settingValues[$key] = $s->string();
            }
            $fields += ['action' => $s->string()->enum(['create', 'update', 'delete', 'reset']), 'values' => $s->object($settingValues), 'keys' => $s->array()->items($s->string()->enum(array_keys($settingValues)))];
        }
        $keys = match (true) {
            $operation === 'prepare_business_action' => array_keys($fields),
            $operation === 'execute_product_import' => ['import_id'],
            $operation === 'update_product_prices' => ['changes', 'category', 'price_type', 'percentage'],
            str_contains($operation, 'product') => [...AiBusinessOperations::PRODUCT_FIELDS, ...($operation === 'create_product' ? ['price', 'prices'] : ['record_id'])],
            str_contains($operation, 'customer') => ['record_id', 'name', 'phone', 'email', 'address', 'note'],
            str_contains($operation, 'supplier') => ['record_id', 'name', 'phone', 'address', 'contact_person', 'note'],
            default => ['record_id', 'title', 'category', 'amount', 'payment_method', 'expense_date', 'note'],
        };
        if (str_starts_with($operation, 'create_')) {
            $keys = array_values(array_diff($keys, ['record_id']));
        }
        $schema = ['data' => $s->object(array_intersect_key($fields, array_flip($keys)))->required()];
        if ($operation === 'prepare_business_action') {
            $schema['operation'] = $s->string()->enum(array_values(array_diff(AiBusinessOperations::OPERATIONS, ['create_purchase_draft'])))->required();
        }

        return $schema;
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->toolName;
        try {
            $actions = app(AiBusinessActions::class);
            abort_unless($this->agent->conversationId && $this->agent->messageId, 403, 'A signed-in conversation is required.');
            $actor = $this->agent->actor;
            $conversation = $this->agent->conversationId;
            $args = $request->all();
            $allowed = in_array($this->toolName, ['get_action_preview', 'get_action_status', 'execute_confirmed_action'], true) ? ['action_id', 'offset', 'limit'] : ($this->toolName === 'preview_product_import' ? ['import_id'] : ['data', ...($this->toolName === 'prepare_business_action' ? ['operation'] : [])]);
            abort_if(array_diff(array_keys($args), $allowed), 422, 'Unsupported action arguments.');
            if (in_array('action_id', $allowed, true)) {
                $data = $request->validate(['action_id' => 'required|uuid', 'offset' => 'sometimes|integer|min:0|max:5000', 'limit' => 'sometimes|integer|min:1|max:20']);
                $result = $this->toolName === 'execute_confirmed_action'
                    ? $actions->modelExecutionStatus($actor, $conversation, $data['action_id'])
                    : $actions->get($actor, $conversation, $data['action_id'], $data['offset'] ?? 0, $data['limit'] ?? 20);
            } elseif ($this->toolName === 'preview_product_import') {
                $data = $request->validate(['import_id' => 'sometimes|uuid']);
                $actions->authorize($actor, $conversation);
                $id = $data['import_id'] ?? $this->agent->importId;
                abort_unless($id && DB::table('product_imports')->where('id', $id)->where('business_id', $actor->business_id)->where('user_id', $actor->id)->exists(), 404, 'Attach a product CSV first.');
                $result = ['import' => app(ProductImportService::class)->summary($id),
                    'issues' => DB::table('product_import_rows')->where('import_id', $id)->whereIn('status', ['invalid', 'failed', 'skipped'])->orderBy('row_number')->limit(20)->get(['row_number', 'name', 'status', 'errors']), 'products_imported_by_this_tool' => false];
            } else {
                $data = $request->validate(['data' => 'required|array|min:1', 'operation' => [$this->toolName === 'prepare_business_action' ? 'required' : 'prohibited', Rule::in(array_values(array_diff(AiBusinessOperations::OPERATIONS, ['create_purchase_draft'])))]]);
                $result = $actions->prepare($actor, $conversation, $this->agent->messageId, $data['operation'] ?? $this->toolName, $data['data']);
            }
        } catch (ValidationException $e) {
            $result = ['error' => 'invalid_arguments', 'fields' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            $result = ['error' => 'action_unavailable', 'status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'action_unavailable', 'message' => 'The proposal could not be prepared. No business change was saved.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
