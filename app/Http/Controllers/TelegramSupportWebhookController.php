<?php

namespace App\Http\Controllers;

use App\Services\TelegramSupport\Inbox;
use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TelegramClient;
use Illuminate\Http\Request;

class TelegramSupportWebhookController extends Controller
{
    public function __invoke(Request $request, Inbox $inbox)
    {
        $settings = app(Settings::class)->get();
        if (! $settings['enabled']) {
            return response()->json(['ok' => false], 404);
        }
        $secret = (string) $settings['webhook_secret'];
        if (strlen($secret) < 32 || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)) {
            return response()->json(['ok' => false], 503);
        }
        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            return response()->json(['ok' => false], 403);
        }
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['ok' => false], 413);
        }
        $update = json_decode($request->getContent(), true);
        if (! is_array($update) || ! isset($update['update_id']) || ! is_int($update['update_id'])) {
            return response()->json(['ok' => false], 400);
        }
        $botId = TelegramClient::idFor((string) $settings['bot_token']);
        if ($botId === null) {
            return response()->json(['ok' => false], 503);
        }
        // Bind identity to the same settings snapshot that authenticated this update.
        $inbox->accept($update, $botId);

        return response()->json(['ok' => true]);
    }
}
