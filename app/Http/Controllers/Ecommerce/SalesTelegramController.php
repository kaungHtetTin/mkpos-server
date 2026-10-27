<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\SalesTelegram\Connections;
use App\Services\SalesTelegram\Identity;
use App\Services\ShopCustomerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesTelegramController extends Controller
{
    private function connection(Request $request): object
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'owner', 403, 'Only the business owner can manage Sales AI.');

        return app(Connections::class)->ensure((int) $user->business_id);
    }

    public function show(Request $request): array
    {
        return ['settings' => app(Connections::class)->view($this->connection($request))];
    }

    public function update(Request $request): array
    {
        $row = $this->connection($request);
        $data = $request->validate(['version' => 'required|integer|min:1', 'enabled' => 'required|boolean', 'groups_enabled' => 'required|boolean',
            'daily_limit' => 'required|integer|min:1|max:10000', 'per_user_limit' => 'required|integer|min:1|max:500',
            'bot_token' => ['nullable', 'string', 'max:256', 'regex:/^[1-9]\d{0,15}:[A-Za-z0-9_-]{20,}$/D']]);

        return ['settings' => app(Connections::class)->save($row->business_id, $data)];
    }

    public function test(Request $request): array
    {
        $info = app(Connections::class)->inspect($this->connection($request));
        unset($info['existing_url']);

        return ['connection' => $info];
    }

    public function register(Request $request): array
    {
        $row = $this->connection($request);
        $data = $request->validate(['version' => 'required|integer|min:1', 'replace_existing' => 'required|boolean']);

        return ['settings' => app(Connections::class)->register($row->business_id, (int) $data['version'], (bool) $data['replace_existing'])];
    }

    public function groups(Request $request): array
    {
        $row = $this->connection($request);
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:120', 'offset' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:50']);
        $query = DB::table('sales_telegram_groups')->where('connection_id', $row->id)->where('bot_id', $row->bot_id)
            ->when(filled($data['q'] ?? null), fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$data['q'].'%')->orWhere('chat_id', 'like', '%'.$data['q'].'%')));

        return ['total' => (clone $query)->count(), 'items' => $query->orderBy('id')->offset($data['offset'] ?? 0)->limit($data['limit'] ?? 20)->get()];
    }

    public function group(Request $request, int $group): array
    {
        $row = $this->connection($request);

        return ['group' => DB::table('sales_telegram_groups')->where('connection_id', $row->id)->where('bot_id', $row->bot_id)->where('id', $group)->firstOrFail()];
    }

    public static function groupRules(): array
    {
        return ['version' => 'required|integer|min:0', 'chat_id' => 'required|integer|min:-4503599627370495|max:-1', 'title' => 'required|string|max:255',
            'status' => 'required|in:pending,approved,disabled', 'reply_mentions' => 'required|boolean', 'reply_to_bot' => 'required|boolean',
            'daily_limit' => 'required|integer|min:1|max:10000', 'per_user_limit' => 'required|integer|min:1|max:500'];
    }

    public function saveGroup(Request $request, ?int $group = null): array
    {
        $connection = $this->connection($request);
        $data = $request->validate(self::groupRules());
        abort_unless($connection->bot_id, 422, 'Save a bot token first.');

        return DB::transaction(function () use ($connection, $group, $data) {
            // Same lock order as webhook admission: connection before group.
            DB::table('sales_telegram_connections')->where('id', $connection->id)->lockForUpdate()->first();
            if ($group) {
                $row = DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('id', $group)->lockForUpdate()->firstOrFail();
                abort_unless((int) $row->version === (int) $data['version'], 409, 'Group settings changed. Reload before saving.');
                abort_unless((int) $row->chat_id === (int) $data['chat_id'], 422, 'Group ID cannot be changed.');
                DB::table('sales_telegram_groups')->where('id', $row->id)->update(array_diff_key($data, ['version' => true]) + ['version' => $row->version + 1, 'updated_at' => now('UTC')]);
                $id = $row->id;
            } else {
                abort_if(DB::table('sales_telegram_groups')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('chat_id', $data['chat_id'])->exists(), 409, 'This group is already listed.');
                $id = DB::table('sales_telegram_groups')->insertGetId(array_diff_key($data, ['version' => true]) + ['connection_id' => $connection->id, 'bot_id' => $connection->bot_id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }

            return ['group' => DB::table('sales_telegram_groups')->where('id', $id)->first()];
        });
    }

    public function link(Request $request, string $uuid): array
    {
        $customer = $request->attributes->get('platform_customer');
        abort_unless($customer, 401);
        $thread = DB::table('shop_chat_threads')->where('uuid', $uuid)->where('platform_customer_id', $customer->id)->firstOrFail();
        abort_if(ShopCustomerAccess::blocked($thread->business_id, $thread->phone, 'account:'.$customer->id), 403);

        return app(Identity::class)->issue((int) $thread->business_id, (int) $customer->id);
    }
}
