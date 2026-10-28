<?php

namespace App\Services\SalesTelegram;

use App\Services\EcommerceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Identity
{
    public const PUBLIC_TOOLS = ['search_products', 'shop_information', 'get_product_details', 'compare_products', 'recommend_products', 'get_sales_policies', 'get_delivery_options', 'calculate_quote', 'request_human'];

    public const ACCOUNT_TOOLS = ['customer_orders', 'get_customer_order_details', 'get_customer_request_status'];

    public function context(object $thread): object
    {
        if (($thread->sales_ai_channel ?? 'web') !== 'telegram') {
            return $thread;
        }
        $copy = clone $thread;
        $copy->platform_customer_id = null;
        $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->first();
        $copy->telegram_private = $conversation && $conversation->chat_id > 0;
        $copy->telegram_conversation_id = $conversation?->id;
        if ($conversation && $conversation->chat_id > 0 && app(Inbox::class)->current($thread)) {
            $copy->platform_customer_id = DB::table('sales_telegram_links')->where('connection_id', $conversation->connection_id)
                ->where('bot_id', $conversation->bot_id)->where('sender_id', $conversation->sender_id)->value('platform_customer_id');
            if ($copy->platform_customer_id && ! DB::table('platform_customers')->where('id', $copy->platform_customer_id)->where('is_active', true)->exists()) {
                $copy->platform_customer_id = null;
            }
        }

        return $copy;
    }

    public static function permits(object $thread, string $tool): bool
    {
        return ($thread->sales_ai_channel ?? 'web') !== 'telegram' || in_array($tool, self::PUBLIC_TOOLS, true)
            || (($thread->telegram_private ?? false) && (in_array($tool, self::ACCOUNT_TOOLS, true) || in_array($tool, ['prepare_checkout', 'submit_order_payment_proof'], true)));
    }

    public function issue(int $business, int $customer): array
    {
        $connection = DB::table('sales_telegram_connections')->where('business_id', $business)->where('enabled', true)->whereNotNull('registered_at')->first();
        abort_unless($connection && $connection->bot_username, 422, 'This shop has not connected a Telegram bot.');
        $token = Str::random(40);
        DB::transaction(function () use ($connection, $customer, $token) {
            DB::table('sales_telegram_link_tokens')->where('connection_id', $connection->id)->where('platform_customer_id', $customer)->whereNull('used_at')->update(['used_at' => now('UTC')]);
            DB::table('sales_telegram_link_tokens')->insert(['connection_id' => $connection->id, 'platform_customer_id' => $customer,
                'token_hash' => hash('sha256', $token), 'connection_version' => $connection->version, 'expires_at' => now('UTC')->addMinutes(10), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        });

        return ['url' => 'https://t.me/'.$connection->bot_username.'?start=link_'.$token, 'expires_in' => 600];
    }

    public function orders(object $thread, string $tool, array $arguments): array
    {
        abort_unless(($thread->telegram_private ?? false) && $thread->telegram_conversation_id, 403);
        $reference = validator($arguments, ['reference' => 'sometimes|nullable|string|max:40'])->validate()['reference'] ?? null;
        $guest = DB::table('sales_telegram_checkouts')->where('conversation_id', $thread->telegram_conversation_id)->where('step', 'ordered')->whereNotNull('result_encrypted')->pluck('order_reference');
        $query = DB::table('ecommerce_orders')->where('business_id', $thread->business_id)->where(fn ($q) => $q->where(fn ($guestOrders) => $guestOrders->whereIn('reference', $guest)->whereNull('platform_customer_id'))
            ->when($thread->platform_customer_id, fn ($q) => $q->orWhere('platform_customer_id', $thread->platform_customer_id)));
        if ($reference) {
            $query->where('reference', $reference);
        }
        $rows = $query->orderByDesc('id')->limit(5)->get();
        if ($tool === 'customer_orders') {
            return ['items' => $rows->map(fn ($row) => array_intersect_key((array) $row, array_flip(['reference', 'status', 'payment_status', 'fulfillment', 'total', 'currency', 'created_at'])))->all()];
        }
        abort_unless($reference && $rows->count() === 1, 404, 'This order is not available to this Telegram customer.');
        $view = app(EcommerceService::class)->orderView($rows->first());

        return $tool === 'get_customer_request_status' ? ['reference' => $reference, 'requests' => $view['customer_requests'] ?? []] : $view;
    }

    /** Caller holds the connection admission lock. Tokens never enter chat history. */
    public function consume(object $connection, int $sender, string $token): bool
    {
        $claim = DB::table('sales_telegram_link_tokens')->where('connection_id', $connection->id)->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now('UTC'))->where('connection_version', $connection->version)->lockForUpdate()->first();
        if (! $claim) {
            return false;
        }
        $existing = DB::table('sales_telegram_links')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)
            ->where(fn ($q) => $q->where('sender_id', $sender)->orWhere('platform_customer_id', $claim->platform_customer_id))->lockForUpdate()->get();
        if ($existing->contains(fn ($row) => (int) $row->sender_id !== $sender || (int) $row->platform_customer_id !== (int) $claim->platform_customer_id)) {
            return false;
        }
        DB::table('sales_telegram_link_tokens')->where('id', $claim->id)->update(['used_at' => now('UTC')]);
        DB::table('sales_telegram_links')->updateOrInsert(['connection_id' => $connection->id, 'bot_id' => $connection->bot_id, 'sender_id' => $sender],
            ['platform_customer_id' => $claim->platform_customer_id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->rotate($connection, $sender);

        return true;
    }

    public function unlink(object $connection, int $sender): void
    {
        DB::table('sales_telegram_links')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('sender_id', $sender)->delete();
        $this->rotate($connection, $sender);
    }

    private function rotate(object $connection, int $sender): void
    {
        $threads = DB::table('sales_telegram_conversations')->where('connection_id', $connection->id)->where('bot_id', $connection->bot_id)->where('sender_id', $sender)->where('chat_id', '>', 0)->pluck('thread_id');
        DB::table('shop_chat_threads')->whereIn('id', $threads)->increment('sales_ai_version');
        DB::table('sales_telegram_checkouts')->whereIn('thread_id', $threads)->whereNotIn('step', ['ordered', 'cancelled'])->update(['step' => 'cancelled']);
        DB::table('sales_telegram_conversations')->where('connection_id',$connection->id)->where('bot_id',$connection->bot_id)->where('sender_id',$sender)->where('chat_id','>',0)->update(['thread_id' => null, 'updated_at' => now('UTC')]);
    }
}
