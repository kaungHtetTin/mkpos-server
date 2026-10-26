<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\TelegramSupport\Groups;
use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TelegramSupportGroupsController extends Controller
{
    private function bot(): int
    {
        abort_unless(Schema::hasTable('telegram_support_groups'), 503, 'Run the Telegram group migration first.');

        return app(TelegramClient::class)->botId();
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['search' => 'nullable|string|max:200', 'status' => ['nullable', Rule::in(['pending', 'approved', 'disabled'])],
            'per_page' => ['sometimes', Rule::in([10, 25, 50, 100])], 'page' => 'sometimes|integer|min:1']);
        $botId = $this->bot();
        $query = DB::table('telegram_support_groups')->where('bot_id', $botId);
        if (filled($data['search'] ?? null)) {
            $search = $data['search'];
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('chat_id', $search));
        }
        if (filled($data['status'] ?? null)) {
            $query->where('status', $data['status']);
        }
        $perPage = (int) ($data['per_page'] ?? 25);
        $total = $query->count();
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($total / $perPage)));
        $lastError = DB::table('customer_service_messages as m')->join('customer_service_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereColumn('c.bot_id', 'telegram_support_groups.bot_id')->whereColumn('c.chat_id', 'telegram_support_groups.chat_id')
            ->whereNotNull('m.error_code')->orderByDesc('m.updated_at')->limit(1)->select('m.error_code');
        $groups = $query->select('telegram_support_groups.*')->selectSub($lastError, 'last_error')->orderByDesc('id')->forPage($page, $perPage)->get();
        $activity = DB::table('customer_service_messages as m')->join('customer_service_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.bot_id', $botId)->whereIn('c.chat_id', $groups->pluck('chat_id'))->select('c.chat_id')
            ->selectRaw('MAX(CASE WHEN m.status = ? THEN m.updated_at END) as last_reply_at', ['delivered'])
            ->selectRaw('SUM(CASE WHEN m.uses_ai = ? AND m.created_at >= ? THEN 1 ELSE 0 END) as requests_today', [true, now('Asia/Yangon')->startOfDay()->utc()])
            ->groupBy('c.chat_id')->get()->keyBy('chat_id');
        $settings = app(Settings::class)->get();
        foreach ($groups as $group) {
            $group->reply_blocker = ! $settings['enabled'] ? 'service_disabled' : (! $settings['groups_enabled'] ? 'group_replies_disabled'
                : (! $settings['bot_username'] ? 'bot_username_missing' : ($group->status !== 'approved' ? 'not_approved' : (! $group->is_member ? 'not_a_member' : null))));
            $group->last_update = app(Groups::class)->diagnostic($group);
            $group->last_reply_at = $activity->get($group->chat_id)?->last_reply_at;
            $group->requests_today = (int) ($activity->get($group->chat_id)?->requests_today ?? 0);
            $group->chat_id = (string) $group->chat_id;
            foreach (['last_seen_at', 'last_reply_at'] as $field) {
                $group->$field = $group->$field ? CarbonImmutable::parse($group->$field, 'UTC')->toIso8601String() : null;
            }
        }

        return ['groups' => $groups, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))];
    }

    public function store(Request $request): array
    {
        $botId = $this->bot();
        $data = $request->validate(['chat_id' => 'required|integer|min:-4503599627370495|max:-1', 'title' => 'required|string|max:255']);
        abort_if(DB::table('telegram_support_groups')->where('bot_id', $botId)->where('chat_id', $data['chat_id'])->exists(), 409, 'This group is already listed.');
        DB::table('telegram_support_groups')->insertOrIgnore($data + ['bot_id' => $botId, 'is_member' => false, 'status' => 'pending', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return ['message' => 'Group added. Review and approve it to allow replies.'];
    }

    public function update(Request $request, int $group): array
    {
        $botId = $this->bot();
        $data = $request->validate(['version' => 'required|integer|min:1', 'status' => ['required', Rule::in(['pending', 'approved', 'disabled'])],
            'reply_mentions' => 'required|boolean', 'reply_to_bot' => 'required|boolean',
            'daily_limit' => 'required|integer|min:1|max:10000', 'per_user_daily_limit' => 'required|integer|min:1|max:500']);
        abort_if($data['status'] === 'approved' && ! $data['reply_mentions'] && ! $data['reply_to_bot'], 422, 'Enable mentions or replies before approving this group.');
        DB::transaction(function () use ($data, $group, $botId) {
            $row = DB::table('telegram_support_groups')->where('id', $group)->where('bot_id', $botId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->version === $data['version'], 409, 'Group settings changed. Reload the groups before saving.');
            DB::table('telegram_support_groups')->where('id', $row->id)->update(array_replace($data, ['version' => $row->version + 1, 'updated_at' => now('UTC')]));
            $conversations = DB::table('customer_service_conversations')->where('bot_id', $botId)->where('chat_id', $row->chat_id);
            $ids = (clone $conversations)->pluck('id');
            $conversations->increment('version');
            DB::table('customer_service_messages')->whereIn('conversation_id', $ids)->whereIn('status', ['queued', 'processing', 'ready'])
                ->update(['status' => 'cancelled', 'error_code' => 'group_settings_changed', 'updated_at' => now('UTC')]);
        }, 3);

        return ['message' => 'Group settings saved.'];
    }
}
