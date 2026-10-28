<?php

namespace App\Services\SalesAi;

use App\Services\ShopChatRealtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/** Trusted form outcomes, never client assertions, trigger conversational follow-ups. */
class ActionFeedback
{
    public function ready(): bool { return Schema::hasColumn('shop_chat_messages', 'sales_ai_action_event'); }

    public function record(object $thread, string $id, string $operation, ?array $failure = null): void
    {
        if (!$this->ready()) return;
        DB::transaction(function () use ($thread, $id, $operation, $failure) {
            DB::table('businesses')->where('id', $thread->business_id)->lockForUpdate()->first();
            $a = DB::table('sales_ai_actions')->where('id', $id)->where('business_id', $thread->business_id)->where('thread_id', $thread->id)->where('platform_customer_id', $thread->platform_customer_id)->whereNotNull('message_id')->lockForUpdate()->first();
            if (!$a) return;
            $current = DB::table('shop_chat_threads')->where('id', $thread->id)->lockForUpdate()->first();
            $preview = json_decode($a->preview, true);
            $outcome = $failure ? 'failed' : ($operation === 'confirm' ? 'completed' : ($operation === 'cancel' ? 'cancelled' : 'reviewed'));
            if (!$failure && (($outcome === 'completed' && $a->status !== 'completed') || ($outcome === 'cancelled' && $a->status !== 'cancelled') || ($outcome === 'reviewed' && $a->status !== 'pending'))) return;
            $result = $outcome === 'completed' ? json_decode($a->result, true) : [];
            $result += array_intersect_key($preview, array_flip(['reference', 'currency', 'subtotal', 'discount', 'delivery_fee', 'total', 'ready', 'immediate_cancellation']));
            if ($outcome === 'reviewed') $result['saved'] = false;
            if ($outcome === 'cancelled') $result = ['proposal_cancelled' => true, 'order_cancelled' => false];
            if ($failure) $result = ['saved' => false, 'error' => $failure['message'], 'fields' => array_keys($failure['fields'] ?? [])];
            if ($a->kind === 'cart' && $outcome === 'completed') $result['items'] = array_map(fn ($p) => array_intersect_key($p, array_flip(['name', 'unit_name', 'quantity'])), $preview['items'] ?? []);
            $event = ['action_id' => $a->id, 'kind' => $a->kind, 'operation' => $operation, 'outcome' => $outcome, 'revision' => (int) $a->revision, 'result' => $result];
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'mkpos-sales-action:'.$id.':'.$operation.':'.$a->revision.':'.hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)))->toString();
            if (DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('client_id', $key)->exists()) return;
            $label = str_replace('_', ' ', $a->kind);
            $body = 'Form activity: '.$label.' '.match ($outcome) { 'completed' => 'submitted successfully.', 'cancelled' => 'proposal cancelled. No order was cancelled by this button.', 'reviewed' => 'review refreshed; not submitted yet.', default => 'could not be completed. Review the form errors.' };
            $messageId = DB::table('shop_chat_messages')->insertGetId(['thread_id' => $thread->id, 'client_id' => $key, 'sender' => 'customer', 'body' => $body, 'sales_ai_action_event' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ...\App\Support\ChatTime::message()]);
            DB::table('shop_chat_threads')->where('id', $thread->id)->update([...\App\Support\ChatTime::thread()]);
            app(Chat::class)->enqueue($current, DB::table('shop_chat_messages')->where('id', $messageId)->first());
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->changed($current, $messageId));
        });
    }

    public function current(object $run): bool
    {
        if (!$this->ready()) return true;
        $message = DB::table('shop_chat_messages')->where('id', $run->trigger_message_id)->where('thread_id', $run->thread_id)->first();
        if (!$message || !$message->sales_ai_action_event) return true;
        $e = json_decode($message->sales_ai_action_event, true);
        $a = DB::table('sales_ai_actions')->where('id', $e['action_id'])->where('thread_id', $run->thread_id)->where('business_id', $run->business_id)->first();
        if (!$a || (int) $a->revision !== $e['revision']) return false;
        $status = match ($e['outcome']) { 'completed' => 'completed', 'cancelled' => 'cancelled', default => 'pending' };
        if ($status === 'pending' && \Carbon\CarbonImmutable::parse($a->expires_at, 'UTC')->isPast()) return false;
        $latest = DB::table('shop_chat_messages')->where('thread_id', $run->thread_id)->where('sales_ai_action_event->action_id', $a->id)->orderByDesc('id')->value('id');
        return $a->status === $status && (int) $latest === (int) $message->id;
    }
}
