<?php

namespace App\Http\Controllers;

use App\Services\SalesTelegram\Inbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SalesTelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $connection)
    {
        $row = DB::table('sales_telegram_connections')->where('uuid', $connection)->where('enabled', true)->whereNotNull('registered_at')->first();
        abort_unless($row, 404);
        abort_unless(hash_equals(Crypt::decryptString($row->secret_encrypted), (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        $update = json_decode($request->getContent(), true);
        abort_unless(is_array($update) && is_int($update['update_id'] ?? null) && $update['update_id'] >= 0, 400);
        app(Inbox::class)->accept($row, $update);
        // Telegram can execute this fixed acknowledgement directly from the webhook response.
        $callback = $update['callback_query']['id'] ?? null;

        return response()->json(is_string($callback) && strlen($callback) <= 128 ? ['method' => 'answerCallbackQuery', 'callback_query_id' => $callback] : ['ok' => true]);
    }
}
