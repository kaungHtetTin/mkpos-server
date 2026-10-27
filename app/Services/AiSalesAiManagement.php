<?php

namespace App\Services;

use App\Http\Controllers\Ecommerce\SalesAiController;
use App\Models\User;
use App\Services\SalesAi\Chat;
use App\Services\SalesAi\Pricing;
use App\Services\SalesAi\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Owner-only Sales AI configuration, scoped reads and reviewable native actions. */
class AiSalesAiManagement
{
    public const READS = ['get_sales_ai_settings', 'get_sales_ai_activity', ...AiSalesTelegramManagement::READS];
    public const OPERATIONS = ['manage_sales_ai_settings', 'manage_sales_ai_tools', 'manage_sales_ai_policies', 'manage_sales_ai_handover', 'manage_sales_ai_topups', ...AiSalesTelegramManagement::OPERATIONS];

    public function ready(): bool { return Schema::hasTable('sales_ai_settings') && Schema::hasTable('sales_ai_topups') && Schema::hasColumn('sales_ai_settings', 'sales_policies'); }
    private function business(): int
    {
        abort_unless($this->ready(), 503, 'Sales AI setup is required.');
        abort_unless(in_array('shop', config('mkpos.enabled_modules', []), true), 403, 'Shop module unavailable.');
        return app(TenantContext::class)->requireId();
    }
    private function table(string $name) { return DB::table($name)->where('business_id', $this->business()); }
    private function keys(array $a, array $allowed): void { abort_if(array_diff(array_keys($a), $allowed), 422, 'Unsupported Sales AI fields.'); }

    public function read(string $tool, array $a): array
    {
        $business = $this->business();
        if (in_array($tool, AiSalesTelegramManagement::READS, true)) return app(AiSalesTelegramManagement::class)->read($tool, $a);
        if ($tool === 'get_sales_ai_settings') {
            $this->keys($a, []);
            $wallet = $this->table('sales_ai_wallets')->first();
            $platform = app(AiAssistantSettings::class)->get();
            return ['settings' => app(Settings::class)->get($business), 'tools' => collect(Settings::TOOLS)->map(fn ($description, $name) => ['name' => $name, 'description' => $description])->values()->all(),
                'always_available_tool' => 'request_human', 'wallet' => ['currency' => 'MMK', 'balance' => Pricing::amount((int) ($wallet->balance_micros ?? 0)), 'reserved' => Pricing::amount((int) ($wallet->reserved_micros ?? 0)), 'available' => Pricing::amount(max(0, (int) ($wallet->balance_micros ?? 0) - (int) ($wallet->reserved_micros ?? 0)))],
                'platform' => ['enabled' => (bool) $platform['enabled'], 'configured' => (bool) $platform['configured']], 'pricing' => ['usd_to_mmk_rate' => $platform['usd_to_mmk_rate'], 'markup_percent' => $platform['sales_ai_markup_percent']],
                'shop_access_valid' => app(EcommerceService::class)->eligible($business), 'storefront_enabled' => (bool) $this->table('storefront_settings')->value('storefront_enabled'),
                'note' => 'Configuration does not guarantee responses: active shop access, enabled storefront, configured/funded platform AI and sufficient Sales AI wallet credit are required. No credentials are returned.'];
        }
        $this->keys($a, ['resource', 'thread_uuid', 'record_id', 'limit', 'offset']);
        $a = validator($a, ['resource' => ['required', Rule::in(['wallet_entries', 'topups', 'conversations', 'runs', 'usage'])], 'thread_uuid' => 'sometimes|uuid', 'record_id' => 'sometimes|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:20', 'offset' => 'sometimes|integer|min:0|max:1000000'])->validate();
        $resource = $a['resource']; $limit = $a['limit'] ?? 10; $offset = $a['offset'] ?? 0;
        if ($resource === 'usage') {
            $this->keys($a, ['resource']);
            return app(\App\Services\SalesAi\WalletUsage::class)->report($business, DB::table('businesses')->where('id', $business)->value('timezone') ?: 'Asia/Rangoon');
        }
        abort_if(isset($a['thread_uuid']) && !in_array($resource, ['conversations', 'runs']), 422, 'Thread filtering applies only to conversations and runs.');
        abort_if(isset($a['record_id']) && $resource !== 'topups', 422, 'Use record_id for top-up details only.');
        $q = $this->table(match ($resource) { 'wallet_entries' => 'sales_ai_entries', 'topups' => 'sales_ai_topups', 'conversations' => 'shop_chat_threads', 'runs' => 'sales_ai_runs' });
        if (isset($a['record_id'])) $q->where('id', $a['record_id']);
        if (isset($a['thread_uuid'])) {
            $thread = $this->table('shop_chat_threads')->where('uuid', $a['thread_uuid'])->first();
            abort_unless($thread, 404, 'Conversation unavailable in this business.');
            $q->where($resource === 'runs' ? 'thread_id' : 'id', $thread->id);
        }
        $columns = match ($resource) {
            'wallet_entries' => ['id', 'type', 'amount_micros', 'balance_after_micros', 'description', 'created_at'],
            'topups' => ['id', 'amount_micros', 'status', 'version', 'transaction_reference', 'created_at', 'reviewed_at'],
            'conversations' => ['uuid', 'customer_name', 'sales_ai_mode', 'sales_ai_version', 'sales_ai_handoff_reason', 'updated_at'],
            'runs' => ['id', 'thread_id', 'status', 'reason', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'charge_micros', 'created_at'],
        };
        $total = (clone $q)->count();
        $rows = $q->orderByDesc($resource === 'conversations' ? 'updated_at' : 'created_at')->orderByDesc('id')->offset($offset)->limit($limit)->get($columns)->map(function ($row) {
            $item = (array) $row;
            foreach (['amount_micros' => 'amount', 'balance_after_micros' => 'balance_after', 'charge_micros' => 'charge'] as $from => $to) if (isset($item[$from])) { $item[$to] = Pricing::amount((int) $item[$from]); unset($item[$from]); }
            return $item;
        })->all();
        return ['resource' => $resource, 'currency' => 'MMK', 'items' => $rows, 'total' => $total, 'has_more' => $offset + count($rows) < $total, 'next_offset' => $offset + count($rows) < $total ? $offset + count($rows) : null, 'note' => 'Owned business records only. No provider credentials, private image paths, customer tokens or Office approval tools. Top-up submission uses the native screenshot-validation form; only Office approval credits the wallet.'];
    }

    private function targets(array $a): array
    {
        $this->keys($a, ['scope', 'thread_uuid', 'mode']);
        $v = validator($a, ['scope' => 'required|in:conversation,all', 'thread_uuid' => 'required_if:scope,conversation|prohibited_if:scope,all|uuid', 'mode' => 'required|in:ai,human'])->validate();
        $q = $this->table('shop_chat_threads')->when($v['scope'] === 'conversation', fn ($q) => $q->where('uuid', $v['thread_uuid']));
        $rows = $q->orderBy('id')->limit(5001)->lockForUpdate()->get(['id', 'uuid', 'customer_name', 'sales_ai_mode', 'sales_ai_version'])->map(fn ($r) => (array) $r)->all();
        abort_if(count($rows) > 5000, 422, 'Select a single conversation; this business exceeds 5,000 conversations.');
        abort_unless($rows, 404, 'No matching conversation in this business.');
        return $rows;
    }

    private function topup(int $id): array
    {
        $row = $this->table('sales_ai_topups')->where('id', $id)->lockForUpdate()->first(['id', 'status', 'version', 'amount_micros']);
        abort_unless($row, 404, 'Top-up unavailable in this business.');
        return (array) $row;
    }

    public function build(string $operation, array $a, int $userId): array
    {
        $business = $this->business();
        if (in_array($operation, AiSalesTelegramManagement::OPERATIONS, true)) return app(AiSalesTelegramManagement::class)->build($operation, $a);
        $settings = app(Settings::class)->get($business);
        if ($operation === 'manage_sales_ai_handover') {
            $targets = $this->targets($a);
            abort_if($a['mode'] === 'ai' && !$settings['enabled'], 422, 'Enable Sales AI before handing conversations to AI.');
            $preview = [];
            foreach ($targets as $row) if ($row['sales_ai_mode'] !== $a['mode']) $preview[] = ['name' => $row['customer_name'] ?: $row['uuid'], 'before' => ['conversation' => $row['uuid'], 'handling' => $row['sales_ai_mode']], 'after' => ['conversation' => $row['uuid'], 'handling' => $a['mode'], 'effect' => $a['mode'] === 'ai' ? 'AI may reply to the latest unanswered customer message using normal Sales AI credit.' : 'Human handles replies; pending stale AI responses are suppressed.']];
            abort_unless($preview, 422, 'The selected conversations already use this handling mode.');
            return ['payload' => ['input' => $a, 'targets' => $targets], 'baseline' => ['settings_version' => $settings['version'], 'targets' => $targets], 'preview' => $preview];
        }
        if ($operation === 'manage_sales_ai_topups') {
            $this->keys($a, ['action', 'record_id']);
            $v = validator($a, ['action' => 'required|in:cancel', 'record_id' => 'required|integer|min:1'])->validate();
            $row = $this->topup($v['record_id']); abort_unless($row['status'] === 'pending', 409, 'Only a pending top-up can be cancelled.');
            return ['payload' => ['input' => $v], 'baseline' => $row, 'preview' => [['name' => 'Sales AI top-up #'.$row['id'], 'before' => ['status' => 'pending', 'amount' => Pricing::amount((int) $row['amount_micros']), 'currency' => 'MMK'], 'after' => ['status' => 'cancelled', 'effect' => 'Cancels the pending request only. No funds are transferred, credited or refunded.']]]];
        }
        $this->keys($a, ['data']); abort_unless(!empty($a['data']) && is_array($a['data']), 422, 'Specify the requested Sales AI changes.');
        $data = $a['data']; $next = $settings; $preview = [];
        if ($operation === 'manage_sales_ai_settings') {
            $this->keys($data, ['enabled', 'name', 'instructions', 'soul']);
            $next = array_replace($settings, $data);
        } elseif ($operation === 'manage_sales_ai_tools') {
            $this->keys($data, ['enable', 'disable']);
            $v = validator($data, ['enable' => 'sometimes|array|max:20', 'disable' => 'sometimes|array|max:20', 'enable.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Settings::TOOLS))], 'disable.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Settings::TOOLS))]])->validate();
            abort_if(array_intersect($v['enable'] ?? [], $v['disable'] ?? []), 422, 'A tool cannot be both enabled and disabled.');
            $next['tool_permissions'] = array_values(array_diff(array_unique([...$settings['tool_permissions'], ...($v['enable'] ?? [])]), $v['disable'] ?? []));
        } elseif ($operation === 'manage_sales_ai_policies') {
            $this->keys($data, Settings::POLICY_FIELDS);
            $next['sales_policies'] = array_replace($settings['sales_policies'], $data);
        } else abort(422, 'Unsupported Sales AI action.');
        $next = validator($next, Settings::rules())->validate();
        $next['enabled'] = (bool) $next['enabled'];
        foreach (['name', 'instructions', 'soul'] as $field) $next[$field] = trim($next[$field]);
        if ($operation === 'manage_sales_ai_policies') foreach (array_keys($data) as $field) $next['sales_policies'][$field] = trim($next['sales_policies'][$field] ?? '');
        foreach (['enabled', 'name', 'instructions', 'soul', 'tool_permissions', 'sales_policies'] as $field) {
            if ($field === 'tool_permissions') {
                foreach (array_keys(Settings::TOOLS) as $tool) {
                    $before = in_array($tool, $settings['tool_permissions'], true); $after = in_array($tool, $next['tool_permissions'], true);
                    if ($before !== $after) $preview[] = ['name' => $tool, 'before' => ['enabled' => $before], 'after' => ['enabled' => $after]];
                }
            } elseif ($field === 'sales_policies') {
                foreach (Settings::POLICY_FIELDS as $policy) if (($settings['sales_policies'][$policy] ?? '') !== ($next['sales_policies'][$policy] ?? '')) $preview[] = ['name' => $policy.' policy', 'before' => ['published_text' => $settings['sales_policies'][$policy] ?? ''], 'after' => ['published_text' => $next['sales_policies'][$policy] ?? '', 'effect' => empty($next['sales_policies'][$policy]) ? 'Policy unpublished; the representative must ask for human clarification.' : 'Customers may be told these exact published terms.']];
            } elseif ($settings[$field] !== $next[$field]) $preview[] = ['name' => 'Sales AI '.$field, 'before' => [$field => $settings[$field]], 'after' => [$field => $next[$field]]];
        }
        abort_unless($preview, 422, 'No Sales AI values would change.');
        $preview[0]['after']['effect'] = ($preview[0]['after']['effect'] ?? '').' Saving advances the Sales AI settings version; pending AI replies and customer proposals using old settings become stale. Human chat and mandatory human handoff remain available.';
        return ['payload' => ['input' => $a, 'settings' => $next], 'baseline' => ['settings' => $settings], 'preview' => $preview];
    }

    public function unchanged(string $operation, array $p, array $before): bool
    {
        $business = $this->business();
        if (in_array($operation, AiSalesTelegramManagement::OPERATIONS, true)) return app(AiSalesTelegramManagement::class)->unchanged($p, $before);
        if ($operation === 'manage_sales_ai_topups') return $this->topup($p['input']['record_id']) == $before;
        $settings = app(Settings::class)->get($business);
        return $operation === 'manage_sales_ai_handover' ? $before == ['settings_version' => $settings['version'], 'targets' => $this->targets($p['input'])] : $before['settings'] == $settings;
    }

    public function execute(string $operation, array $p, int $userId): array
    {
        $business = $this->business();
        if (in_array($operation, AiSalesTelegramManagement::OPERATIONS, true)) return app(AiSalesTelegramManagement::class)->execute($operation, $p, $userId);
        if ($operation === 'manage_sales_ai_handover') {
            $count = 0;
            foreach ($p['targets'] as $row) if ($row['sales_ai_mode'] !== $p['input']['mode']) { app(Chat::class)->handoff((object) $row, $p['input']['mode'], (int) $row['sales_ai_version']); $count++; }
            return ['saved' => true, 'updated_conversations' => $count, 'handling' => $p['input']['mode'], 'modules' => ['shop']];
        }
        if ($operation === 'manage_sales_ai_topups') {
            $owner = User::where('business_id', $business)->findOrFail($userId);
            $row = $this->topup($p['input']['record_id']);
            $request = Request::create('/', 'POST', ['version' => (int) $row['version']]); $request->setUserResolver(fn () => $owner);
            $saved = app(SalesAiController::class)->cancel($request, (int) $row['id']);
            return ['saved' => true, 'topup_id' => $row['id'], 'status' => $saved['request']['status'], 'sales_ai_topup' => $saved['request'], 'wallet_credited' => false, 'modules' => ['shop']];
        }
        app(Settings::class)->update($business, $p['settings']);
        return ['saved' => true, 'sales_ai' => $this->read('get_sales_ai_settings', []), 'modules' => ['shop']];
    }
}
