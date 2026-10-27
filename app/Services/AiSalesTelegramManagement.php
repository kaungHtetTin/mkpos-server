<?php

namespace App\Services;

use App\Http\Controllers\Ecommerce\SalesTelegramController;
use App\Models\User;
use App\Services\SalesTelegram\Connections;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The owner action pipeline authorizes reads and confirmations; no Telegram HTTP calls here. */
class AiSalesTelegramManagement
{
    public const READS = ['get_sales_ai_telegram_settings', 'get_sales_ai_telegram_groups'];

    public const OPERATIONS = ['manage_sales_ai_telegram_settings', 'manage_sales_ai_telegram_groups'];

    private const SETTINGS = ['enabled', 'groups_enabled', 'daily_limit', 'per_user_limit'];

    private const GROUP_FIELDS = ['chat_id', 'title', 'status', 'reply_mentions', 'reply_to_bot', 'daily_limit', 'per_user_limit'];

    private function keys(array $data, array $allowed): void
    {
        abort_if(array_diff(array_keys($data), $allowed), 422, 'Unsupported Telegram management fields. Enter tokens only in Telegram bot settings.');
    }

    private function connection(): ?object
    {
        abort_unless(Schema::hasTable('sales_telegram_connections') && Schema::hasTable('sales_telegram_groups'), 503, 'Sales AI Telegram setup is required.');

        return DB::table('sales_telegram_connections')->where('business_id', app(TenantContext::class)->requireId())->lockForUpdate()->first();
    }

    private function snapshot(?object $connection): array
    {
        return $connection ? ['id' => $connection->id, 'bot_id' => $connection->bot_id, 'version' => $connection->version,
            'registered_at' => $connection->registered_at, 'token_configured' => (bool) $connection->token_encrypted,
            ...array_intersect_key((array) $connection, array_flip(self::SETTINGS))] : [];
    }

    private function groups(object $connection)
    {
        return DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id);
    }

    private function group(object $connection, int $id): array
    {
        $row = $this->groups($connection)->where('id', $id)->lockForUpdate()->first(['id', 'version', 'is_member', ...self::GROUP_FIELDS]);
        abort_unless($row, 404, 'Telegram group unavailable in this business.');

        return (array) $row;
    }

    public function read(string $tool, array $input): array
    {
        $connection = $this->connection();
        if ($tool === 'get_sales_ai_telegram_settings') {
            $this->keys($input, []);

            return ['settings' => $connection ? app(Connections::class)->view($connection) : null,
                'initialized' => (bool) $connection, 'screen' => 'sales_ai_telegram',
                'note' => 'Saved flags only, not a live worker or Telegram connectivity check. Enter/replace tokens, test connection and register the webhook on the native Telegram bot settings page. No secrets, customer identities, links, history or private receipts are returned.'];
        }
        $this->keys($input, ['record_id', 'query', 'limit', 'offset']);
        $data = validator($input, ['record_id' => 'sometimes|integer|min:1', 'query' => 'sometimes|string|max:120', 'limit' => 'sometimes|integer|min:1|max:20', 'offset' => 'sometimes|integer|min:0|max:1000000'])->validate();
        if (! $connection) {
            return ['items' => [], 'total' => 0, 'has_more' => false, 'next_offset' => null];
        }
        $query = $this->groups($connection)->when($data['record_id'] ?? null, fn ($query, $id) => $query->where('id', $id))
            ->when($data['query'] ?? null, fn ($query, $search) => $query->where('title', 'like', '%'.$search.'%'));
        if (isset($data['record_id'])) {
            abort_unless((clone $query)->exists(), 404, 'Telegram group unavailable in this business.');
        }
        $total = (clone $query)->count();
        $offset = $data['offset'] ?? 0;
        $limit = $data['limit'] ?? 10;
        $items = $query->orderBy('id')->offset($offset)->limit($limit)->get(['id', 'version', 'is_member', ...self::GROUP_FIELDS, 'last_reason', 'last_seen_at'])->all();

        return ['items' => $items, 'total' => $total, 'has_more' => $offset + count($items) < $total,
            'next_offset' => $offset + count($items) < $total ? $offset + count($items) : null,
            'note' => 'Only this connection/current bot groups. Approval does not add the bot to Telegram, enable group replies, prove permissions or register a webhook. Read connection settings as well.'];
    }

    public function build(string $operation, array $input): array
    {
        $connection = $this->connection();
        abort_unless($connection, 422, 'Open Telegram bot settings and save the bot token first.');
        $baseline = ['connection' => $this->snapshot($connection)];
        if ($operation === 'manage_sales_ai_telegram_settings') {
            $this->keys($input, ['data']);
            validator($input, ['data' => 'required|array'])->validate();
            $this->keys($input['data'] ?? [], self::SETTINGS);
            $before = array_intersect_key((array) $connection, array_flip(self::SETTINGS));
            $next = validator(array_replace($before, $input['data'] ?? []), ['enabled' => 'required|boolean', 'groups_enabled' => 'required|boolean', 'daily_limit' => 'required|integer|min:1|max:10000', 'per_user_limit' => 'required|integer|min:1|max:500'])->validate();
            abort_if($next['enabled'] && ! $connection->token_encrypted, 422, 'Save a bot token before enabling Telegram.');
            foreach (['enabled', 'groups_enabled'] as $key) {
                $before[$key] = (bool) $before[$key];
                $next[$key] = (bool) $next[$key];
            }
            $payload = ['settings' => $next, 'input' => $input];
            $name = 'Sales AI Telegram settings';
        } else {
            $this->keys($input, ['action', 'record_id', 'data']);
            $action = validator($input, ['action' => 'required|in:create,update', 'record_id' => 'required_if:action,update|prohibited_if:action,create|integer|min:1', 'data' => 'required|array'])->validate()['action'];
            abort_unless($connection->bot_id, 422, 'Save a bot token first.');
            $this->keys($input['data'], $action === 'create' ? self::GROUP_FIELDS : array_diff(self::GROUP_FIELDS, ['chat_id']));
            $old = $action === 'update' ? $this->group($connection, $input['record_id']) : null;
            $baseline['group'] = $old;
            $before = $old ? array_intersect_key($old, array_flip(self::GROUP_FIELDS)) : [];
            $next = validator(array_replace(['version' => $old['version'] ?? 0, 'status' => 'pending', 'reply_mentions' => true, 'reply_to_bot' => true, 'daily_limit' => 100, 'per_user_limit' => 20], $before, $input['data']), SalesTelegramController::groupRules())->validate();
            if (! $old) {
                abort_if($this->groups($connection)->where('chat_id', $next['chat_id'])->exists(), 409, 'This group is already listed.');
            }
            foreach (['reply_mentions', 'reply_to_bot'] as $key) {
                if ($old) {
                    $before[$key] = (bool) $before[$key];
                } $next[$key] = (bool) $next[$key];
            }
            $payload = ['group' => $next, 'input' => $input];
            $name = 'Sales AI Telegram group';
            unset($next['version']);
        }
        abort_if($before == $next, 422, 'No Telegram values would change.');

        return ['payload' => $payload, 'baseline' => $baseline, 'preview' => [['name' => $name, 'before' => $before,
            'after' => $next + ['effect' => 'Changes saved reply permissions and limits only. No bot token, webhook, Telegram membership, account link or customer history is changed. Old queued replies may become stale. Approval alone does not guarantee replies.']]]];
    }

    public function unchanged(array $payload, array $baseline): bool
    {
        $connection = $this->connection();
        if (! $connection || $this->snapshot($connection) != $baseline['connection']) {
            return false;
        }
        if (! array_key_exists('group', $baseline)) {
            return true;
        }

        return $baseline['group'] === null ? ! $this->groups($connection)->where('chat_id', $payload['group']['chat_id'])->exists()
            : $this->group($connection, $baseline['group']['id']) == $baseline['group'];
    }

    public function execute(string $operation, array $payload, int $userId): array
    {
        $connection = $this->connection();
        if ($operation === 'manage_sales_ai_telegram_settings') {
            $settings = app(Connections::class)->save($connection->business_id, $payload['settings'] + ['version' => $connection->version]);

            return ['saved' => true, 'sales_ai_telegram' => ['settings' => $settings], 'modules' => ['shop']];
        }
        $owner = User::where('business_id', $connection->business_id)->findOrFail($userId);
        $request = Request::create('/', 'POST', $payload['group']);
        $request->setUserResolver(fn () => $owner);
        $result = app(SalesTelegramController::class)->saveGroup($request, $payload['input']['record_id'] ?? null);

        return ['saved' => true, 'sales_ai_telegram' => $result + ['created' => $payload['input']['action'] === 'create'], 'modules' => ['shop']];
    }
}
