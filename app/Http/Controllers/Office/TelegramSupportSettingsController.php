<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\TelegramSupport\Connection;
use App\Services\TelegramSupport\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramSupportSettingsController extends Controller
{
    private function tokenRules(): array
    {
        return ['nullable', 'string', 'max:256', 'regex:/^[1-9]\d{0,15}:[A-Za-z0-9_-]{20,}$/D'];
    }

    public function show(Settings $settings): array
    {
        return ['settings' => $settings->publicData()];
    }

    public function update(Request $request, Settings $settings): array
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'enabled' => 'required|boolean',
            'webhook_url' => 'nullable|string|max:2048', 'daily_limit' => 'required|integer|min:1|max:10000',
            'per_chat_daily_limit' => 'required|integer|min:1|max:500', 'bot_token' => $this->tokenRules()]);
        $settings->save($data);

        return $this->show($settings) + ['message' => 'Telegram settings saved.'];
    }

    public function test(Request $request, Settings $settings, Connection $connection): array
    {
        $data = $request->validate(['bot_token' => $this->tokenRules()]);
        $candidate = $settings->get();
        if (filled($data['bot_token'] ?? null)) {
            $candidate['bot_token'] = $data['bot_token'];
        }

        return ['connection' => $connection->inspect($candidate), 'message' => 'Telegram connection verified.'];
    }

    public function register(Request $request, Settings $settings, Connection $connection): array
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'replace_existing' => 'sometimes|boolean']);
        $info = $settings->locked($data['version'], function ($current) use ($connection, $data) {
            $info = $connection->register($current, $data['replace_existing'] ?? false);
            DB::table('telegram_support_settings')->where('id', 1)->update(['version' => $current['version'] + 1,
                'bot_username' => $info['bot_username'], 'last_registered_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $info;
        });

        return $this->show($settings) + ['connection' => $info, 'message' => 'Telegram webhook registered.'];
    }
}
