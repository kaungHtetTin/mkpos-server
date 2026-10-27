<?php

namespace App\Services\SalesTelegram;

use App\Ai\Agents\SalesRepresentative;
use App\Http\Controllers\Ecommerce\ShopController;
use App\Services\SalesAi\RepresentativeTools;
use App\Services\SalesAi\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Telegram is an authenticated transport, not model consent. Only signed human callbacks submit. */
class Checkout
{
    private function text(object $draft, string $key, array $values = []): string
    {
        return __('sales_telegram.'.$key, $values, $draft->language);
    }

    private function payload(object $draft): array
    {
        return json_decode(Crypt::decryptString($draft->payload_encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    private function active(object $conversation, bool $lock = false): ?object
    {
        return DB::table('sales_telegram_checkouts')->where('conversation_id', $conversation->id)->where('thread_id', $conversation->thread_id)
            ->whereNotIn('step', ['ordered', 'cancelled'])->where('expires_at', '>', now('UTC'))->orderByDesc('created_at')->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function prepare(SalesRepresentative $agent, object $thread, array $arguments): array
    {
        return DB::transaction(function () use ($agent, $thread, $arguments) {
            $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->first();
            abort_unless($conversation && $conversation->chat_id > 0, 422, 'Checkout and personal details are available only in the bot private chat.');
            abort_unless($agent->runId && $agent->messageId && app(Inbox::class)->current($thread, $agent->messageId), 403);
            $run = DB::table('sales_ai_runs')->where('id', $agent->runId)->where('thread_id', $thread->id)->where('trigger_message_id', $agent->messageId)->where('status', 'processing')->firstOrFail();
            $items = app(RepresentativeTools::class)->items($arguments['items'] ?? []);
            app(RepresentativeTools::class)->cartPreview($thread, $items);
            $connection = DB::table('sales_telegram_connections')->where('id', $conversation->connection_id)->lockForUpdate()->firstOrFail();
            $conversation = DB::table('sales_telegram_conversations')->where('id', $conversation->id)->where('thread_id', $thread->id)->lockForUpdate()->firstOrFail();
            $source = DB::table('sales_telegram_updates')->where('conversation_id', $conversation->id)->where('trigger_message_id', $agent->messageId)->lockForUpdate()->firstOrFail();
            abort_unless($connection->enabled && $connection->registered_at && (int) $source->connection_version === (int) $connection->version, 403);
            abort_unless(app(Inbox::class)->current($thread, $agent->messageId), 403);
            $existing = $this->active($conversation, true);
            if (! $existing) {
                $id = (string) Str::uuid();
                DB::table('sales_telegram_checkouts')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'thread_id' => $thread->id, 'run_id' => $run->id,
                    'connection_version' => $connection->version, 'language' => ($run->language ?? '') === 'my' ? 'my' : 'en', 'step' => 'start',
                    'payload_encrypted' => Crypt::encryptString(json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'expires_at' => now('UTC')->addMinutes(30), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            } else {
                $id = $existing->id;
            }

            return ['checkout_id' => $id, 'customer_confirmation_required' => true, 'guest_checkout_available' => true, 'instructions' => 'The customer must tap Continue checkout, supply their own contact and fulfillment details, review all products/fees and press Confirm order. No order has been placed; no stock is reserved. Do not guess these details.'];
        });
    }

    private function button(object $draft, object $conversation, string $action, string $label): array
    {
        $body = 'sc:'.str_replace('-', '', $draft->id).':'.base_convert((string) $draft->version, 10, 36).':'.$action;
        $signature = substr(hash_hmac('sha256', $body.':'.$conversation->connection_id.':'.$conversation->bot_id.':'.$conversation->sender_id.':'.$conversation->chat_id, config('app.key')), 0, 16);
        $data = $body.':'.$signature;
        abort_if(strlen($data) > 64, 500);

        return ['text' => mb_substr($label, 0, 70), 'callback_data' => $data];
    }

    public function keyboardFor(object $conversation): array
    {
        if ($conversation->chat_id < 0 || ! $conversation->thread_id || ! ($draft = $this->active($conversation))) {
            return [];
        }

        return ['inline_keyboard' => [[$this->button($draft, $conversation, 'o', $this->text($draft, 'open'))]]];
    }

    public function cancel(object $conversation): void
    {
        DB::table('sales_telegram_checkouts')->where('conversation_id', $conversation->id)->whereNotIn('step', ['ordered', 'cancelled'])->update(['step' => 'cancelled', 'version' => DB::raw('version + 1'), 'updated_at' => now('UTC')]);
    }

    /** Returns true when a guided form consumed the message. Other chat does not become consent. */
    public function input(object $connection, object $conversation, int $update, string $text): bool
    {
        if ($conversation->chat_id < 0 || ! ($draft = $this->active($conversation))) {
            return false;
        }
        if ($text === '/cancel') {
            $this->cancel($conversation);
            app(Delivery::class)->notice($update, $this->text($draft, 'cancelled'));

            return true;
        }
        if ($draft->step === 'start') {
            return false;
        }
        $payload = $this->payload($draft);
        $step = $draft->step;
        if (! in_array($step, ['name', 'phone', 'address', 'coupon', 'notes'], true)) {
            $this->prompt($connection, $conversation, $draft, $update);

            return true;
        }
        $rules = match ($step) {
            'name' => ['required', 'string', 'max:255', 'regex:/\S/u'], 'phone' => ['required', 'string', 'regex:/^[+0-9()\s-]{6,80}$/D'],
            'address' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'coupon' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'notes' => ['nullable', 'string', 'max:2000'],
        };
        $value = $text === '/skip' && in_array($step, ['coupon', 'notes'], true) ? null : $text;
        if (validator(['value' => $value], ['value' => $rules])->fails()) {
            app(Delivery::class)->notice($update, $this->text($draft, 'invalid'), [], $draft->thread_id);
            $this->prompt($connection, $conversation, $draft, $update);

            return true;
        }
        $field = match ($step) {
            'name' => 'customer_name','phone' => 'phone','address' => 'address','coupon' => 'coupon_code','notes' => 'notes'
        };
        $payload[$field] = $value;
        $next = match ($step) {
            'name' => 'phone','phone' => 'fulfillment','address' => 'payment','coupon' => 'notes','notes' => 'review'
        };
        $draft = $this->save($draft, $payload, $next);
        $this->prompt($connection, $conversation, $draft, $update);

        return true;
    }

    private function save(object $draft, array $payload, string $step, array $extra = []): object
    {
        DB::table('sales_telegram_checkouts')->where('id', $draft->id)->update($extra + ['step' => $step, 'version' => $draft->version + 1,
            'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'updated_at' => now('UTC')]);

        return DB::table('sales_telegram_checkouts')->where('id', $draft->id)->first();
    }

    public function callback(object $connection, object $conversation, int $update, string $data): void
    {
        $language = preg_match('/\p{Myanmar}/u', $data) ? 'my' : 'en';
        if ($conversation->chat_id < 0 || ! preg_match('/^(sc:([a-f0-9]{32}):([a-z0-9]+):([a-z0-9]+)):([a-f0-9]{16})$/D', $data, $m)) {
            return;
        }
        $signature = substr(hash_hmac('sha256', $m[1].':'.$conversation->connection_id.':'.$conversation->bot_id.':'.$conversation->sender_id.':'.$conversation->chat_id, config('app.key')), 0, 16);
        if (! hash_equals($signature, $m[5])) {
            return;
        }
        $uuid = substr($m[2], 0, 8).'-'.substr($m[2], 8, 4).'-'.substr($m[2], 12, 4).'-'.substr($m[2], 16, 4).'-'.substr($m[2], 20);
        $draft = DB::table('sales_telegram_checkouts')->where('id', $uuid)->where('conversation_id', $conversation->id)->where('thread_id', $conversation->thread_id)->lockForUpdate()->first();
        if (! $draft) {
            return;
        }
        if ($draft->step === 'ordered') {
            app(Delivery::class)->notice($update, $this->text($draft, 'ordered', ['reference' => $draft->order_reference]));

            return;
        }
        if ((int) $draft->version !== (int) base_convert($m[3], 36, 10) || $draft->step === 'cancelled' || now('UTC')->greaterThan($draft->expires_at) || $draft->connection_version !== $connection->version) {
            app(Delivery::class)->notice($update, $this->text($draft, 'stale'));

            return;
        }
        $action = $m[4];
        $payload = $this->payload($draft);
        if ($action === 'x') {
            $this->cancel($conversation);
            app(Delivery::class)->notice($update, $this->text($draft, 'cancelled'));

            return;
        }
        if ($action === 'c' && $draft->step === 'review') {
            $this->confirm($connection, $conversation, $draft, $update);

            return;
        }
        if (($action === 'o' && $draft->step === 'start') || $action === 'e') {
            $draft = $this->save($draft, $payload, 'name', ['quote_encrypted' => null]);
        } elseif ($action === 's' && in_array($draft->step, ['coupon', 'notes'], true)) {
            $this->input($connection, $conversation, $update, '/skip');

            return;
        } elseif (preg_match('/^f([01])$/D', $action, $choice) && $draft->step === 'fulfillment') {
            $payload['fulfillment'] = $choice[1] === '0' ? 'pickup' : 'delivery';
            unset($payload['delivery_zone_id']);
            $draft = $this->save($draft, $payload, $payload['fulfillment'] === 'delivery' ? 'zone' : 'payment');
        } elseif (in_array($draft->step, ['zone', 'payment'], true)) {
            $thread = DB::table('shop_chat_threads')->where('id', $draft->thread_id)->firstOrFail();
            $options = app(RepresentativeTools::class)->options($thread);
            $choices = $draft->step === 'zone' ? [...array_values($options['delivery_zones']), $options['external_courier']] : array_values($options['payment_methods']);
            if (in_array($action, ['n', 'b'], true)) {
                $payload['page'] = max(0, min((int) ceil(count($choices) / 8) - 1, ($payload['page'] ?? 0) + ($action === 'n' ? 1 : -1)));
                $draft = $this->save($draft, $payload, $draft->step);
            } elseif (preg_match('/^v([a-z0-9]+)$/D', $action, $choice)) {
                $index = (int) base_convert($choice[1], 36, 10);
                $selected = $payload['choices'][$index] ?? null;
                if ($selected === null || ! collect($choices)->contains(fn ($entry) => (string) $entry['id'] === (string) $selected)) {
                    app(Delivery::class)->notice($update, $this->text($draft, 'changed'));

                    return;
                }
                $payload[$draft->step === 'zone' ? 'delivery_zone_id' : 'payment_method_id'] = $selected;
                unset($payload['page'],$payload['choices']);
                $draft = $this->save($draft, $payload, $draft->step === 'zone' ? 'address' : 'coupon');
            }
        }
        $this->prompt($connection, $conversation, $draft, $update);
    }

    private function quote(object $draft): array
    {
        $thread = DB::table('shop_chat_threads')->where('id', $draft->thread_id)->firstOrFail();
        abort_unless(app(Inbox::class)->current($thread), 403);
        $store = app(RepresentativeTools::class)->store($thread);

        return app(ShopController::class)->quote(Request::create('/', 'POST', $this->payload($draft)), $store->slug);
    }

    private function prompt(object $connection, object $conversation, object $draft, int $update, string $prefix = ''): void
    {
        $payload = $this->payload($draft);
        $rows = [];
        $text = $prefix.$this->text($draft, $draft->step);
        if ($draft->step === 'fulfillment') {
            $rows[] = [$this->button($draft, $conversation, 'f0', $this->text($draft, 'pickup')), $this->button($draft, $conversation, 'f1', $this->text($draft, 'delivery'))];
        }
        if (in_array($draft->step, ['zone', 'payment'], true)) {
            $thread = DB::table('shop_chat_threads')->where('id', $draft->thread_id)->firstOrFail();
            $options = app(RepresentativeTools::class)->options($thread);
            $choices = $draft->step === 'zone' ? [...array_values($options['delivery_zones']), $options['external_courier']] : array_values($options['payment_methods']);
            $ids = array_map(fn ($entry) => (string) $entry['id'], $choices);
            if (isset($payload['choices']) && $payload['choices'] !== $ids) {
                $payload['choices'] = $ids;
                $draft = $this->save($draft, $payload, $draft->step);
            }
            $payload['choices'] = $ids;
            DB::table('sales_telegram_checkouts')->where('id', $draft->id)->update(['payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
            $page = max(0, (int) ($payload['page'] ?? 0));
            foreach (array_slice($choices, $page * 8, 8, true) as $index => $choice) {
                $rows[] = [$this->button($draft, $conversation, 'v'.base_convert((string) $index, 10, 36), (string) $choice['name'])];
            }
            if ($page > 0) {
                $rows[] = [$this->button($draft, $conversation, 'b', $this->text($draft, 'previous'))];
            }
            if (count($choices) > ($page + 1) * 8) {
                $rows[] = [$this->button($draft, $conversation, 'n', $this->text($draft, 'more'))];
            }
        }
        if (in_array($draft->step, ['coupon', 'notes'], true)) {
            $rows[] = [$this->button($draft, $conversation, 's', $this->text($draft, 'skip'))];
        }
        if ($draft->step === 'review') {
            try {
                $quote = $this->quote($draft);
                $previous = $draft->quote_encrypted ? json_decode(Crypt::decryptString($draft->quote_encrypted), true) : null;
                if ($previous && ! hash_equals($previous['quote_hash'], $quote['quote_hash'])) {
                    $draft = $this->save($draft, $payload, 'review');
                }
                DB::table('sales_telegram_checkouts')->where('id', $draft->id)->update(['quote_encrypted' => Crypt::encryptString(json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
                $text .= "\n\n";
                foreach ($quote['items'] as $item) {
                    $text .= $item['name'].' × '.$item['quantity'].' '.$item['unit_name'].' = '.$item['line_total'].' '.$quote['currency']."\n";
                }
                foreach (['subtotal' => 'subtotal', 'discount' => 'discount', 'fee' => 'delivery_fee', 'total' => 'total'] as $label => $field) {
                    $text .= $this->text($draft, $label).': '.($quote[$field] ?? 0).' '.$quote['currency']."\n";
                }
                foreach (['customer' => 'customer_name', 'contact' => 'phone', 'destination' => 'address', 'coupon_label' => 'coupon_code', 'note' => 'notes'] as $label => $field) {
                    if (filled($payload[$field] ?? null)) {
                        $text .= $this->text($draft, $label).': '.$payload[$field]."\n";
                    }
                }
                if (filled($quote['fulfillment_instructions'] ?? null)) {
                    $text .= $quote['fulfillment_instructions']."\n";
                }
                $text .= $this->text($draft, $payload['fulfillment'])."\n".$this->text($draft, 'method').': '.implode(' · ', array_filter(array_map(fn ($k) => $quote['payment_method'][$k] ?? null, ['name', 'bank', 'account_name', 'account_no', 'instructions'])))."\n";
                if (($quote['delivery_fee_recipient'] ?? '') === 'delivery_service') {
                    $text .= $this->text($draft, 'courier')."\n";
                }
                $rows[] = [$this->button($draft, $conversation, 'c', $this->text($draft, 'confirm'))];
            } catch (\Throwable) {
                $text = $this->text($draft, 'error');
            }
            $rows[] = [$this->button($draft, $conversation, 'e', $this->text($draft, 'edit'))];
        }
        $rows[] = [$this->button($draft, $conversation, 'x', $this->text($draft, 'cancel'))];
        app(Delivery::class)->notice($update, $text, ['inline_keyboard' => $rows], $draft->thread_id);
    }

    private function confirm(object $connection, object $conversation, object $draft, int $update): void
    {
        $settings = app(Settings::class)->get($connection->business_id);
        if (! $settings['enabled'] || ! in_array('prepare_checkout', $settings['tool_permissions'], true)) {
            $this->cancel($conversation);
            app(Delivery::class)->notice($update, $this->text($draft, 'unavailable'), [], $draft->thread_id);

            return;
        }
        try {
            abort_unless($draft->quote_encrypted, 409);
            $quote = json_decode(Crypt::decryptString($draft->quote_encrypted), true, flags: JSON_THROW_ON_ERROR);
            $data = $this->payload($draft);
            unset($data['choices'],$data['page']);
            $data += ['idempotency_key' => $draft->id, 'quote_hash' => $quote['quote_hash']];
            $thread = app(Identity::class)->context(DB::table('shop_chat_threads')->where('id', $draft->thread_id)->firstOrFail());
            abort_unless(app(Inbox::class)->current($thread) && $conversation->chat_id > 0 && (int) $conversation->thread_id === (int) $draft->thread_id, 403);
            $store = app(RepresentativeTools::class)->store($thread);
            $request = Request::create('/', 'POST', $data);
            if ($thread->platform_customer_id) {
                $request->attributes->set('platform_customer', (object) ['id' => $thread->platform_customer_id]);
            }
            $result = app(ShopController::class)->checkout($request, $store->slug);
            DB::table('sales_telegram_checkouts')->where('id', $draft->id)->update(['step' => 'ordered', 'order_reference' => $result['reference'],
                'result_encrypted' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), 'updated_at' => now('UTC')]);
            app(Delivery::class)->notice($update, $this->text($draft, 'ordered', ['reference' => $result['reference']]), [], $draft->thread_id);
        } catch (\Throwable $error) {
            $draft = $this->save($draft, $this->payload($draft), 'review', ['quote_encrypted' => null]);
            $this->prompt($connection, $conversation, $draft, $update, $this->text($draft,'changed')."\n");
        }
    }
}
