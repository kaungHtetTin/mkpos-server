<?php

namespace App\Services\SalesAi;

use App\Ai\Agents\SalesRepresentative;
use App\Http\Controllers\Ecommerce\OrderRequestController;
use App\Http\Controllers\Ecommerce\ShopController;
use App\Services\EcommerceService;
use App\Services\ShopChatRealtime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Customer-session confirmation boundary; models can only prepare or read actions. */
class CustomerActions
{
    public function ready(): bool { return Schema::hasTable('sales_ai_actions') && Schema::hasTable('sales_ai_carts'); }
    private function json(mixed $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private function hash(mixed $data): string { return hash_hmac('sha256', $this->json($data), config('app.key')); }
    private function reader(): RepresentativeTools { return app(RepresentativeTools::class); }

    public function cart(object $thread, bool $lock = false): array
    {
        abort_unless($this->ready() && $thread->platform_customer_id, 503, 'Customer sales tools are being set up.');
        $cart = DB::table('sales_ai_carts')->where('thread_id', $thread->id)->where('business_id', $thread->business_id)->where('platform_customer_id', $thread->platform_customer_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        return ['items' => $cart ? json_decode($cart->items, true) : [], 'version' => (int) ($cart->version ?? 0), 'stock_reserved' => false];
    }

    private function table(object $thread)
    {
        return DB::table('sales_ai_actions')->where('thread_id', $thread->id)->where('business_id', $thread->business_id)->where('platform_customer_id', $thread->platform_customer_id);
    }

    private function orderBaseline(object $order): array
    {
        return array_intersect_key((array) $order, array_flip(['id', 'reference', 'status', 'payment_status', 'sale_id', 'sale_voided', 'total', 'updated_at']));
    }

    private function requestData(string $kind, array $a): array
    {
        $data = validator($a, ['reference' => 'required|string|max:40', 'reason' => ['required', 'string', 'min:5', 'max:2000', 'regex:/\S/u'], 'changes' => 'sometimes|array:address,phone,notes,items', 'changes.address' => 'sometimes|string|max:2000', 'changes.phone' => 'sometimes|string|max:80', 'changes.notes' => 'sometimes|string|max:2000', 'changes.items' => 'sometimes|array|min:1|max:100', 'image_message_id' => 'sometimes|nullable|integer|min:1'])->validate();
        $data['reason'] = trim($data['reason']);
        abort_if(mb_strlen($data['reason']) < 5, 422, 'Enter at least five characters for the reason.');
        if ($kind === 'change') {
            abort_unless(!empty($data['changes']), 422, 'Specify the requested changes.');
            if (isset($data['changes']['items'])) $data['changes']['items'] = $this->reader()->items($data['changes']['items']);
        } else abort_if(isset($data['changes']), 422, 'Changes are only supported for order-change requests.');
        return $data;
    }

    private function assertRequest(object $thread, object $order, string $kind): void
    {
        abort_if(DB::table('shop_order_requests')->where('business_id', $thread->business_id)->where('order_id', $order->id)->where('type', $kind)->exists(), 409, 'This request already exists. Check its current status.');
        if ($kind === 'return') abort_unless($order->status === 'completed' && !$order->sale_voided && $order->payment_status === 'paid', 409, 'Only paid completed orders can receive return requests.');
        elseif ($kind === 'cancellation') abort_if(in_array($order->status, ['completed', 'cancelled', 'expired', 'return_pending']), 409, 'This order cannot receive a cancellation request.');
        else abort_if(in_array($order->status, ['completed', 'cancelled', 'expired', 'dispatched', 'return_pending']), 409, 'Contact the shop for changes to this order.');
    }

    public function prepare(SalesRepresentative $agent, object $thread, string $tool, array $a): array
    {
        abort_unless($this->ready() && $thread->platform_customer_id && $agent->runId && $agent->messageId, 503, 'Customer review forms are unavailable.');
        $run = DB::table('sales_ai_runs')->where('id', $agent->runId)->where('thread_id', $thread->id)->where('business_id', $thread->business_id)->where('trigger_message_id', $agent->messageId)->where('status', 'processing')->first();
        abort_unless($run, 403, 'An active customer conversation response is required.');
        $key = $this->hash([$agent->messageId, $tool, $a]);
        return DB::transaction(function () use ($agent, $thread, $tool, $a, $key) {
            DB::table('shop_chat_threads')->where('id', $thread->id)->lockForUpdate()->first();
            $existing = $this->table($thread)->where('request_key', $key)->first();
            if ($existing) return $this->modelResult($existing);
            abort_if($this->table($thread)->where('status', 'pending')->where('expires_at', '>', now('UTC'))->count() >= 10, 429, 'Review or cancel pending customer actions first.');
            $cart = $this->cart($thread, true);
            $kind = match ($tool) { 'manage_customer_cart', 'prepare_buy_again' => 'cart', 'prepare_checkout' => 'checkout', 'submit_order_payment_proof' => 'proof', 'request_cancellation' => 'cancellation', 'request_return' => 'return', 'request_order_change' => 'change' };
            $payload = ['cart_version' => $cart['version']]; $preview = [];
            if ($kind === 'cart' || $kind === 'checkout') {
                $skipped = [];
                if ($tool === 'prepare_buy_again') {
                    $reference = validator($a, ['reference' => 'required|string|max:40'])->validate()['reference'];
                    $again = $this->reader()->buyAgain($thread, $reference);
                    $items = array_map(fn ($p) => ['product_id' => $p['id'], 'quantity' => $p['quantity']], $again['items']);
                    abort_unless($items, 422, 'None of the previous order items are currently available in the same selling units. Choose products again.');
                    $skipped = $again['skipped'];
                    $payload['buy_again'] = ['reference' => $reference, 'adjustments' => array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'name', 'quantity', 'previous_quantity', 'unit_name'])), $again['items'])];
                } else {
                    if ($tool === 'manage_customer_cart') abort_unless(in_array($a['action'] ?? '', ['update', 'clear']), 422, 'Choose read/update/clear for the cart.');
                    $items = ($a['action'] ?? '') === 'clear' ? [] : ($a['items'] ?? $cart['items']);
                    if (($a['action'] ?? '') === 'clear') abort_if(isset($a['items']), 422, 'Clear cart must omit items.');
                    if ($tool === 'manage_customer_cart' && ($a['action'] ?? '') === 'update') abort_unless(isset($a['items']), 422, 'Provide the complete updated cart.');
                }
                $payload['items'] = $this->reader()->items($items, $kind === 'cart');
                if (isset($a['coupon_code'])) $payload['coupon_code'] = validator($a, ['coupon_code' => 'string|max:40|regex:/^[A-Za-z0-9_-]+$/'])->validate()['coupon_code'];
                $preview = $this->reader()->cartPreview($thread, $payload['items']) + ['skipped' => $skipped, 'adjustments' => $payload['buy_again']['adjustments'] ?? []];
                $payload['catalog_hash'] = $this->hash($this->reader()->cartPreview($thread, $payload['items']));
                $preview['ready'] = $kind === 'cart';
            } else {
                $reference = validator($a, ['reference' => 'required|string|max:40'])->validate()['reference'];
                $order = $this->reader()->order($thread, $reference, true);
                $payload['order_baseline'] = $this->orderBaseline($order);
                if ($kind === 'proof') {
                    $method = json_decode($order->payment_method, true);
                    abort_unless(($method['type'] ?? '') === 'transfer' && ($method['provider'] ?? 'manual') === 'manual' && $order->payment_status === 'unpaid' && !in_array($order->status, ['completed', 'cancelled', 'expired']), 409, 'This order cannot receive a payment proof.');
                    $payload['reference'] = $reference;
                    $preview = ['reference' => $reference, 'total' => $order->total, 'currency' => $order->currency, 'payment_method' => $method, 'notice' => 'Attach and review the shop-order receipt. Submission does not verify funds or approve payment.', 'ready' => false];
                } else {
                    $payload += $this->requestData($kind, $a);
                    $this->assertRequest($thread, $order, $kind);
                    if (isset($payload['changes']['items'])) $preview['requested_items'] = $this->reader()->cartPreview($thread, $payload['changes']['items'])['items'];
                    $preview += ['reference' => $reference, 'reason' => $payload['reason'], 'changes' => $payload['changes'] ?? null, 'ready' => true, 'immediate_cancellation' => $kind === 'cancellation' && $order->status === 'pending' && $order->payment_status === 'unpaid', 'notice' => $kind === 'change' ? 'Change request only. The owner must review it; item changes may require a new checkout. Existing order prices, stock and address are unchanged.' : ($kind === 'return' ? 'Return request only. The owner reviews goods and any refund separately.' : 'Unpaid pending orders can cancel immediately. Other cancellations await shop review.')];
                }
                $imageId = $a['image_message_id'] ?? null;
                if (!$imageId && $kind === 'proof') {
                    $trigger = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('id', $agent->messageId)->first();
                    if ($trigger?->image_path) $imageId = $agent->messageId;
                }
                if ($imageId) { abort_unless(in_array($kind, ['proof', 'return']), 422, 'Images are supported for payment proofs and returns only.'); $this->reader()->image($thread, $imageId); $payload['image_message_id'] = $imageId; $preview['image_message_id'] = $imageId; $preview['ready'] = false; }
            }
            $id = (string) Str::uuid();
            DB::table('sales_ai_actions')->insert(['id' => $id, 'business_id' => $thread->business_id, 'thread_id' => $thread->id, 'platform_customer_id' => $thread->platform_customer_id, 'trigger_message_id' => $agent->messageId, 'run_id' => $agent->runId, 'tool' => $tool, 'kind' => $kind, 'request_key' => $key, 'thread_version' => $thread->sales_ai_version, 'settings_version' => $agent->settings['version'], 'payload' => $this->json($payload), 'preview' => $this->json($preview), 'preview_hash' => $this->hash([$id, $payload, $preview]), 'expires_at' => now('UTC')->addMinutes(30), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            return $this->modelResult($this->table($thread)->where('id', $id)->first());
        });
    }

    private function modelResult(object $row): array
    {
        return ['action_id' => $row->id, 'kind' => $row->kind, 'status' => $row->status === 'pending' && CarbonImmutable::parse($row->expires_at, 'UTC')->isPast() ? 'expired' : $row->status, 'customer_confirmation_required' => $row->status !== 'completed', 'result' => $row->result ? json_decode($row->result, true) : null, 'message' => 'Use the customer review card. The agent cannot confirm this action or claim it is saved.'];
    }

    public function modelStatus(object $thread, string $id): array
    {
        abort_unless($this->ready(), 503);
        $row = $this->table($thread)->where('id', $id)->where('kind', 'checkout')->first();
        abort_unless($row, 404, 'Checkout action unavailable.');
        return $this->modelResult($row);
    }

    public function publish(string $runId, ?int $messageId): void
    {
        if (!$this->ready()) return;
        DB::table('sales_ai_actions')->where('run_id', $runId)->whereNull('message_id')->where('status', 'pending')->update($messageId ? ['message_id' => $messageId, 'updated_at' => now('UTC')] : ['status' => 'cancelled', 'updated_at' => now('UTC')]);
    }

    public function summaries(int $messageId): array
    {
        if (!$this->ready()) return [];
        return DB::table('sales_ai_actions')->where('message_id', $messageId)->orderBy('created_at')->get()->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'status' => $a->status === 'pending' && CarbonImmutable::parse($a->expires_at, 'UTC')->isPast() ? 'expired' : $a->status])->all();
    }

    public function summariesFor(array $ids): array
    {
        if (!$this->ready() || !$ids) return [];
        return DB::table('sales_ai_actions')->whereIn('message_id', $ids)->orderBy('created_at')->get()->groupBy('message_id')->map(fn ($rows) => $rows->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'status' => $a->status === 'pending' && CarbonImmutable::parse($a->expires_at, 'UTC')->isPast() ? 'expired' : $a->status])->all())->all();
    }

    public function customerThread(Request $request, string $uuid): object
    {
        $customer = $request->attributes->get('platform_customer');
        abort_unless($customer, 401, 'Sign in to your customer account.');
        $thread = DB::table('shop_chat_threads')->where('uuid', $uuid)->where('platform_customer_id', $customer->id)->first();
        abort_unless($thread, 404, 'Conversation unavailable.');
        return $thread;
    }

    private function row(object $thread, string $id, bool $lock = false): object
    {
        abort_unless($this->ready(), 503, 'Customer review forms are being set up.');
        $row = $this->table($thread)->where('id', $id)->whereNotNull('message_id')->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($row, 404, 'Customer action unavailable.');
        return $row;
    }

    public function show(object $thread, string $id): array
    {
        return $this->serialize($thread, $this->row($thread, $id));
    }

    private function serialize(object $thread, object $row): array
    {
        $p = json_decode($row->payload, true); $preview = json_decode($row->preview, true);
        return ['id' => $row->id, 'kind' => $row->kind, 'status' => $row->status === 'pending' && CarbonImmutable::parse($row->expires_at, 'UTC')->isPast() ? 'expired' : $row->status, 'revision' => (int) $row->revision, 'preview_hash' => $row->preview_hash, 'preview' => $preview, 'expires_at' => CarbonImmutable::parse($row->expires_at, 'UTC')->toIso8601String(), 'values' => $p['values'] ?? ['items' => $p['items'] ?? [], 'coupon_code' => $p['coupon_code'] ?? '', 'reason' => $p['reason'] ?? '', 'changes' => $p['changes'] ?? null, 'image_message_id' => $p['image_message_id'] ?? null], 'options' => $row->kind === 'checkout' && $row->status === 'pending' ? $this->reader()->options($thread) : null, 'has_image' => !empty($p['evidence_path']), 'result' => $row->result ? json_decode($row->result, true) : null];
    }

    private function authorizeMutation(object $thread, object $row): void
    {
        $settings = app(Settings::class)->get((int) $thread->business_id);
        $current = DB::table('shop_chat_threads')->where('id', $thread->id)->lockForUpdate()->first();
        abort_unless($current && $current->sales_ai_mode === 'ai' && (int) $current->sales_ai_version === (int) $row->thread_version && $settings['enabled'] && $settings['version'] === (int) $row->settings_version && in_array($row->tool, $settings['tool_permissions'], true), 409, 'Sales AI settings or conversation handling changed. Use the shop page or ask for a fresh proposal.');
        abort_unless($row->status === 'pending' && CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture(), 409, 'This proposal is no longer pending.');
    }

    private function digest(object $row, array $data): void
    {
        $v = validator($data, ['revision' => 'required|integer|min:1', 'preview_hash' => 'required|string|size:64'])->validate();
        abort_unless((int) $row->revision === (int) $v['revision'] && hash_equals($row->preview_hash, $v['preview_hash']), 409, 'Review the latest proposal before continuing.');
    }

    public function review(object $thread, string $id, array $input, ?UploadedFile $file = null): array
    {
        $newPath = null;
        try {
            DB::transaction(function () use ($thread, $id, $input, $file, &$newPath) {
                DB::table('businesses')->where('id', $thread->business_id)->lockForUpdate()->first();
                $row = $this->row($thread, $id, true); $this->authorizeMutation($thread, $row); $this->digest($row, $input);
                abort_if(array_diff(array_keys($input), ['revision', 'preview_hash', 'values']), 422, 'Unsupported review fields.');
                $values = $input['values'] ?? []; abort_unless(is_array($values), 422, 'Review values must be an object.');
                abort_if($file && !in_array($row->kind, ['checkout', 'proof', 'return']), 422, 'Images are supported for checkout, payment proofs and returns only.');
                $p = json_decode($row->payload, true); $preview = json_decode($row->preview, true);
                if ($row->kind === 'checkout') {
                    abort_if(array_diff(array_keys($values), ['items', 'coupon_code', 'fulfillment', 'payment_method_id', 'delivery_zone_id', 'customer_name', 'phone', 'email', 'address', 'notes']), 422, 'Unsupported checkout fields.');
                    $values['items'] = $this->reader()->items($values['items'] ?? $p['items']);
                    $r = Request::create('/', 'POST', $values + ['idempotency_key' => $row->id, 'quote_hash' => str_repeat('0', 64)]);
                    $values = app(ShopController::class)->checkoutData($r, true);
                    $store = $this->reader()->store($thread);
                    $quote = app(EcommerceService::class)->quote($store, $values);
                    if ($file) {
                        $old = $p['evidence_path'] ?? null;
                        $p = array_replace($p, app(CheckoutProof::class)->stage($file, (int) $thread->business_id));
                        $newPath = $p['evidence_path'];
                        if ($old && $old !== $newPath) DB::afterCommit(fn () => Storage::disk('local')->delete($old));
                    }
                    $values['quote_hash'] = $quote['quote_hash'];
                    $p['values'] = $values; $p['cart_version'] = $this->cart($thread, true)['version'];
                    $preview = $quote + ['customer_name' => $values['customer_name'], 'phone' => $values['phone'], 'email' => $values['email'] ?? '', 'address' => $values['address'] ?? '', 'notes' => $values['notes'] ?? '', 'ready' => true, 'notice' => $quote['delivery_fee_recipient'] === 'delivery_service' ? 'Courier charges are payable separately; they are not included in this total. Stock is reserved only on Confirm order.' : 'Stock is reserved only after you press Confirm order.'];
                    $preview['payment_proof_required'] = ($quote['payment_method']['type'] ?? '') !== 'cash';
                    if ($preview['payment_proof_required']) {
                        $preview['ready'] = !empty($p['evidence_path']);
                        if ($preview['ready']) app(CheckoutProof::class)->image($p);
                        else $preview['notice'] = 'Attach and review a payment screenshot before confirming this order. Payment remains subject to shop verification.';
                    }
                } elseif ($row->kind === 'cart') {
                    abort_if($file || array_diff(array_keys($values), ['items']), 422, 'Only cart items may be edited.');
                    $p['items'] = $this->reader()->items($values['items'] ?? $p['items'], true);
                    $p['cart_version'] = $this->cart($thread, true)['version'];
                    $fresh = $this->reader()->cartPreview($thread, $p['items']);
                    $p['catalog_hash'] = $this->hash($fresh); $preview = $fresh + ['ready' => true];
                } else {
                    $allowed = match ($row->kind) { 'proof' => ['image_message_id'], 'return' => ['reason', 'image_message_id'], 'change' => ['reason', 'changes'], default => ['reason'] };
                    abort_if(array_diff(array_keys($values), $allowed), 422, 'Unsupported request/proof fields.');
                    $order = $this->reader()->order($thread, $p['reference'], true);
                    abort_unless($p['order_baseline'] == $this->orderBaseline($order), 409, 'Order changed. Ask for a fresh proposal.');
                    if ($row->kind !== 'proof') {
                        $changed = $this->requestData($row->kind, array_replace(array_intersect_key($p, array_flip(['reference', 'reason', 'changes', 'image_message_id'])), $values));
                        $p = array_replace($p, $changed);
                        $preview['reason'] = $p['reason']; $preview['changes'] = $p['changes'] ?? null;
                        if (isset($p['changes']['items'])) $preview['requested_items'] = $this->reader()->cartPreview($thread, $p['changes']['items'])['items'];
                    }
                    if ($file || !empty($values['image_message_id']) || (!empty($p['image_message_id']) && empty($p['evidence_path']))) {
                        $old = $p['evidence_path'] ?? null;
                        if ($file) {
                            validator(['image' => $file], ['image' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096'])->validate();
                            $newPath = $file->store('sales-ai-action-proofs/'.$thread->business_id, 'local');
                        } else {
                            $imageId = $values['image_message_id'] ?? $p['image_message_id'];
                            $image = $this->reader()->image($thread, $imageId);
                            $newPath = 'sales-ai-action-proofs/'.$thread->business_id.'/'.Str::uuid().'.webp';
                            abort_unless(Storage::disk('local')->copy($image->image_path, $newPath), 503, 'Unable to stage this image.');
                        }
                        $p['evidence_path'] = $newPath; $p['evidence_hash'] = hash_file('sha256', Storage::disk('local')->path($newPath));
                        $preview['image_name'] = $file?->getClientOriginalName() ?? 'Chat image';
                        if ($old && $old !== $newPath) DB::afterCommit(fn () => Storage::disk('local')->delete($old));
                    }
                    $preview['ready'] = $row->kind !== 'proof' || !empty($p['evidence_path']);
                }
                $revision = (int) $row->revision + 1;
                $this->table($thread)->where('id', $id)->update(['payload' => $this->json($p), 'preview' => $this->json($preview), 'revision' => $revision, 'preview_hash' => $this->hash([$id, $revision, $p, $preview]), 'updated_at' => now('UTC')]);
                app(ActionFeedback::class)->record($thread, $id, 'review');
            });
        } catch (\Throwable $e) { if ($newPath) Storage::disk('local')->delete($newPath); throw $e; }
        return $this->show($thread, $id);
    }

    public function confirm(object $thread, string $id, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['revision', 'preview_hash', 'confirmed']), 422, 'Unsupported confirmation fields.');
        validator($input, ['confirmed' => 'required|accepted'])->validate();
        return app(EcommerceService::class)->scoped((int) $thread->business_id, function () use ($thread, $id, $input) {
            DB::transaction(function () use ($thread, $id, $input) {
                DB::table('businesses')->where('id', $thread->business_id)->lockForUpdate()->first();
                $row = $this->row($thread, $id, true); $this->digest($row, $input);
                if ($row->status === 'completed') return;
                $this->authorizeMutation($thread, $row);
                $p = json_decode($row->payload, true); $preview = json_decode($row->preview, true);
                abort_unless($preview['ready'] ?? false, 422, 'Complete and refresh the review before confirming.');
                $result = [];
                if ($row->kind === 'cart') {
                    $cart = $this->cart($thread, true);
                    abort_unless($cart['version'] === $p['cart_version'] && hash_equals($p['catalog_hash'], $this->hash($this->reader()->cartPreview($thread, $p['items']))), 409, 'Cart, price or availability changed. Refresh the review.');
                    DB::table('sales_ai_carts')->updateOrInsert(['thread_id' => $thread->id, 'business_id' => $thread->business_id, 'platform_customer_id' => $thread->platform_customer_id], ['items' => $this->json($p['items']), 'version' => $cart['version'] + 1, 'updated_at' => now('UTC')]);
                    $result = ['cart_saved' => true, 'stock_reserved' => false];
                } elseif ($row->kind === 'checkout') {
                    $cart = $this->cart($thread, true);
                    abort_unless($cart['version'] === $p['cart_version'], 409, 'Your cart changed. Refresh the checkout review.');
                    $saved = app(CheckoutProof::class)->submit($thread, $this->reader()->store($thread), $p['values'], $p);
                    $result = ['reference' => $saved['reference'], 'total' => $saved['total'], 'order_created' => true, 'payment_status' => $saved['payment_status'], 'payment_verified' => false];
                    $remaining = [];
                    foreach ($cart['items'] as $item) {
                        $ordered = collect($p['values']['items'])->firstWhere('product_id', $item['product_id']);
                        $quantity = round((float) $item['quantity'] - (float) ($ordered['quantity'] ?? 0), 3);
                        if ($quantity > 0) $remaining[] = ['product_id' => $item['product_id'], 'quantity' => $quantity];
                    }
                    DB::table('sales_ai_carts')->updateOrInsert(['thread_id' => $thread->id, 'business_id' => $thread->business_id, 'platform_customer_id' => $thread->platform_customer_id], ['items' => $this->json($remaining), 'version' => $cart['version'] + 1, 'updated_at' => now('UTC')]);
                } else {
                    $order = $this->reader()->order($thread, $p['reference'], true);
                    abort_unless($p['order_baseline'] == $this->orderBaseline($order), 409, 'This order changed. Ask for a fresh proposal.');
                    $commerce = app(EcommerceService::class);
                    $r = Request::create('/', 'POST', ['reference' => $order->reference, 'credential' => $commerce->customerTrackingCredential($order), 'type' => $row->kind, 'reason' => $p['reason'] ?? '', 'requested_changes' => $p['changes'] ?? null]);
                    if (!empty($p['evidence_path'])) {
                        abort_unless(Storage::disk('local')->exists($p['evidence_path']) && hash_equals($p['evidence_hash'], hash_file('sha256', Storage::disk('local')->path($p['evidence_path']))), 409, 'Review image changed or is unavailable.');
                        $path = Storage::disk('local')->path($p['evidence_path']);
                        $r->files->set($row->kind === 'proof' ? 'proof' : 'photo', new UploadedFile($path, basename($path), mime_content_type($path), null, true));
                    }
                    if ($row->kind === 'proof') {
                        app(ShopController::class)->proof($r);
                        $result = ['reference' => $order->reference, 'payment_status' => 'proof_submitted', 'payment_verified' => false];
                    } else {
                        $this->assertRequest($thread, $order, $row->kind);
                        app(OrderRequestController::class)->store($r);
                        $saved = DB::table('shop_order_requests')->where('business_id', $thread->business_id)->where('order_id', $order->id)->where('type', $row->kind)->first();
                        $result = ['reference' => $order->reference, 'request_id' => $saved->id, 'request_status' => $saved->status, 'kind' => $row->kind];
                    }
                    if (!empty($p['evidence_path'])) DB::afterCommit(fn () => Storage::disk('local')->delete($p['evidence_path']));
                }
                if ($row->kind === 'checkout' && !empty($p['evidence_path'])) DB::afterCommit(fn () => Storage::disk('local')->delete($p['evidence_path']));
                $this->table($thread)->where('id', $id)->update(['status' => 'completed', 'result' => $this->json($result), 'updated_at' => now('UTC')]);
                app(ActionFeedback::class)->record($thread, $id, 'confirm');
                DB::afterCommit(fn () => app(ShopChatRealtime::class)->changed($thread, (int) $row->message_id));
            }, 3);
            return $this->show($thread, $id);
        });
    }

    public function cancel(object $thread, string $id): array
    {
        DB::transaction(function () use ($thread, $id) {
            DB::table('businesses')->where('id', $thread->business_id)->lockForUpdate()->first();
            $row = $this->row($thread, $id, true);
            abort_if($row->status === 'completed', 409, 'This action was already completed.');
            $p = json_decode($row->payload, true);
            $this->table($thread)->where('id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
            app(ActionFeedback::class)->record($thread, $id, 'cancel');
            if (!empty($p['evidence_path'])) DB::afterCommit(fn () => Storage::disk('local')->delete($p['evidence_path']));
        });
        return $this->show($thread, $id);
    }

    public function imageResponse(object $thread, string $id)
    {
        $row = $this->row($thread, $id); $p = json_decode($row->payload, true);
        abort_unless($row->status === 'pending' && CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture() && !empty($p['evidence_path']) && Storage::disk('local')->exists($p['evidence_path']), 404, 'Review image unavailable.');
        return response()->file(Storage::disk('local')->path($p['evidence_path']), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function prune(): int
    {
        if (!$this->ready()) return 0;
        $count = 0;
        DB::table('sales_ai_actions')->where(function ($q) {
            $q->where(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '<', now('UTC')))
                ->orWhere(fn ($done) => $done->whereIn('status', ['completed', 'cancelled', 'expired'])->where('payload', 'like', '%"evidence_path"%'));
        })->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$count) {
                    // Serialize cleanup with review/confirm, then inspect current state.
                    DB::table('businesses')->where('id', $row->business_id)->lockForUpdate()->first();
                    $current = DB::table('sales_ai_actions')->where('id', $row->id)->lockForUpdate()->first();
                    if (!$current || ($current->status === 'pending' && CarbonImmutable::parse($current->expires_at, 'UTC')->isFuture())) return;
                    $p = json_decode($current->payload, true);
                    if (!empty($p['evidence_path'])) {
                        $path = $p['evidence_path']; unset($p['evidence_path'], $p['evidence_hash']);
                        DB::afterCommit(fn () => Storage::disk('local')->delete($path));
                    }
                    DB::table('sales_ai_actions')->where('id', $current->id)->update(['payload' => $this->json($p), 'status' => $current->status === 'pending' ? 'expired' : $current->status]);
                    $count++;
                });
            }
        });
        return $count;
    }
}
