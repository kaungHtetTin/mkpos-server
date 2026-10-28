<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesRepresentative;
use App\Ai\Agents\SystemAgent;
use App\Services\SalesAi\PublicTools;
use App\Services\SalesAi\Runner;
use App\Services\SalesAi\Settings;
use App\Services\SalesTelegram\Checkout;
use App\Services\SalesTelegram\Delivery;
use App\Services\SalesTelegram\Identity;
use App\Services\SalesTelegram\Inbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesTelegramTest extends TestCase
{
    use DatabaseTransactions;

    private array $owner;

    private object $connection;

    private int $sequence = 100;

    private string $token;

    private string $secret;

    private string $slug;

    private ?array $deliveryResponse = null;

    public function test_telegram_ai_and_owner_messages_round_trip_as_the_same_utc_instants(): void
    {
        $pricing = Cache::get('ai.deepseek.pricing.v2');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28T17:45:00Z'));
        Cache::put('ai.deepseek.pricing.v2', $pricing, 3600);
        try {
            $this->receive($this->update('Hello'));
            $thread = $this->thread();
            $this->assertSame('2026-10-29 00:15:00', $thread->created_at);
            $incoming = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->first();
            $this->assertSame('2026-10-29 00:15:00', $incoming->created_at);
            $this->travel(30)->seconds();
            SalesRepresentative::fake(['Hello from your shop.']);
            $run = DB::table('sales_ai_runs')->where('thread_id', $thread->id)->first();
            app(Runner::class)->process($run->id);
            $this->assertSame('completed', DB::table('sales_ai_runs')->where('id', $run->id)->value('status'));
            $url = '/api/shop-admin/chat/'.$thread->uuid.'/messages';
            $this->getJson($url)->assertOk()->assertJsonPath('items.0.created_at', '2026-10-28 17:45:00')
                ->assertJsonPath('items.1.created_at', '2026-10-28 17:45:30')
                ->assertJsonPath('thread.telegram_last_message_at', '2026-10-28T17:45:00.000000Z');
            $this->travel(30)->seconds();
            $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Human reply'])
                ->assertOk()->assertJsonPath('message.created_at', '2026-10-28 17:46:00');
            $this->assertSame('2026-10-29 00:16:00', DB::table('shop_chat_threads')->where('id', $thread->id)->value('updated_at'));
        } finally { $this->travelBack(); }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        config(['cache.default' => 'array', 'shop_chat.realtime_enabled' => false, 'ai.providers.deepseek.key' => 'fixture-ai-key']);
        URL::forceRootUrl('https://fixture.test');
        URL::forceScheme('https');
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => 'fixture', 'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => 0.3, 'output' => 1.2, 'cached_input' => 0.006]]], 3600);
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true, 'api_key_encrypted' => null, 'usd_to_mmk_rate' => '4000', 'sales_ai_markup_percent' => '5']);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 10_000_000_000]);
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Telegram Shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->slug = $store['slug'];
        $this->putJson('/api/shop-admin/settings', array_replace($store, ['storefront_enabled' => true]))->assertOk();
        $settings = $this->getJson('/api/shop-admin/sales-ai')->assertOk()->json('settings');
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['enabled' => true]))->assertOk();
        DB::table('sales_ai_wallets')->where('business_id', $this->owner['business']['id'])->update(['balance_micros' => 10_000_000_000]);
        $this->token = '990001:'.str_repeat('a', 32);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response($this->deliveryResponse ?? match (true) {
            str_ends_with($request->url(), '/getMe') => ['ok' => true, 'result' => ['id' => 990001, 'is_bot' => true, 'username' => 'fixture_shop_bot', 'can_join_groups' => true]],
            str_ends_with($request->url(), '/getWebhookInfo') => ['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]],
            default => ['ok' => true, 'result' => ['message_id' => 44]],
        }));
        $row = $this->getJson('/api/shop-admin/sales-ai/telegram')->assertOk()->json('settings');
        $row = $this->putJson('/api/shop-admin/sales-ai/telegram', ['version' => $row['version'], 'bot_token' => $this->token, 'enabled' => true, 'groups_enabled' => true, 'daily_limit' => 200, 'per_user_limit' => 20])->assertOk()->json('settings');
        $this->postJson('/api/shop-admin/sales-ai/telegram/register', ['version' => $row['version'], 'replace_existing' => false])->assertOk();
        $this->connection = DB::table('sales_telegram_connections')->where('business_id', $this->owner['business']['id'])->first();
        $this->secret = Crypt::decryptString($this->connection->secret_encrypted);
    }

    private function update(string $text, int $sender = 123, int $chat = 123, array $extra = []): array
    {
        return ['update_id' => ++$this->sequence, 'message' => ['message_id' => $this->sequence, 'from' => ['id' => $sender, 'is_bot' => false, 'first_name' => 'Customer', 'language_code' => 'en'],
            'chat' => ['id' => $chat, 'type' => $chat < 0 ? 'supergroup' : 'private', 'title' => 'Fixture group'], 'text' => $text] + $extra];
    }

    private function receive(array $update): void
    {
        $this->postJson('/integrations/telegram/sales-ai/'.$this->connection->uuid, $update, ['X-Telegram-Bot-Api-Secret-Token' => $this->secret])->assertOk();
    }

    private function thread(int $sender = 123, int $chat = 123): object
    {
        $id = DB::table('sales_telegram_conversations')->where('connection_id', $this->connection->id)->where('sender_id', $sender)->where('chat_id', $chat)->value('thread_id');

        return DB::table('shop_chat_threads')->where('id', $id)->firstOrFail();
    }

    private function click(string $data, int $sender = 123, int $chat = 123): void
    {
        $this->receive(['update_id' => ++$this->sequence, 'callback_query' => ['id' => 'callback-'.$this->sequence, 'from' => ['id' => $sender, 'is_bot' => false],
            'message' => ['message_id' => 44, 'from' => ['id' => 990001, 'is_bot' => true], 'chat' => ['id' => $chat, 'type' => $chat < 0 ? 'supergroup' : 'private']], 'data' => $data]]);
    }

    private function button(string $label): string
    {
        $outbox = DB::table('sales_telegram_outbox')->whereNotNull('reply_markup')->orderByDesc('id')->firstOrFail();
        $buttons = collect(json_decode($outbox->reply_markup, true)['inline_keyboard'])->flatten(1);

        return $buttons->firstWhere('text', $label)['callback_data'];
    }

    private function checkoutDraft(string $method = 'Cash', array $bankDetails = []): object
    {
        $product = $this->postJson('/api/products', ['name' => 'Telegram rice', 'price' => 1500, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 1500]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        DB::table('storefront_settings')->where('business_id', $this->owner['business']['id'])->update(['accepting_orders' => true,
            'payment_methods' => json_encode([['id' => 'cash', 'name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'provider' => 'manual'],
                array_replace(['id' => 'bank', 'name' => 'Bank transfer', 'type' => 'transfer', 'bank' => 'KBZPay', 'account_name' => 'Shop owner', 'account_no' => '09123456789', 'is_active' => true, 'provider' => 'manual'], $bankDetails)])]);
        $this->receive($this->update('I want two bags of rice'));
        $thread = app(Identity::class)->context($this->thread());
        $run = DB::table('sales_ai_runs')->where('thread_id', $thread->id)->firstOrFail();
        DB::table('sales_ai_runs')->where('id', $run->id)->update(['status' => 'processing']);
        $agent = new SalesRepresentative($thread, app(Settings::class)->get($thread->business_id), [], $run->id, $run->trigger_message_id);
        app(PublicTools::class)->handle($agent, 'prepare_checkout', ['items' => [['product_id' => $product['id'], 'quantity' => 2]]]);
        $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->firstOrFail();
        $keyboard = app(Checkout::class)->keyboardFor($conversation);
        $this->click($keyboard['inline_keyboard'][0][0]['callback_data']);
        $this->receive($this->update('Guest Buyer'));
        $this->receive($this->update('09123456789'));
        $this->click($this->button('Pickup'));
        $bankLabel = implode(' - ', array_replace(['bank' => 'KBZPay', 'account_name' => 'Shop owner', 'account_no' => '09123456789'], $bankDetails));
        $this->assertContains($bankLabel, $this->latestButtons());
        $this->assertContains('Cash', $this->latestButtons());
        $this->click($this->button($method === 'Bank transfer' ? $bankLabel : $method));
        $this->click($this->button('Skip'));
        $this->click($this->button('Skip'));
        $draft = DB::table('sales_telegram_checkouts')->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame($method === 'Cash' ? 'review' : 'proof', $draft->step);
        $this->assertNotNull($draft->quote_encrypted);

        return $draft;
    }

    private function sendReceipt(array $overrides = [], bool $process = true): void
    {
        SystemAgent::fake([$this->receiptFields($overrides)]);
        $receipt = UploadedFile::fake()->image('receipt.png');
        $bytes = file_get_contents($receipt->getRealPath());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($bytes) {
            if (str_contains($request->url(), '/file/')) return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
            if (str_ends_with($request->url(), '/getFile')) return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/receipt.png', 'file_size' => strlen($bytes)]]);
            return Http::response(['ok' => true, 'result' => ['message_id' => 44]]);
        });
        $this->receive($this->update('', extra: ['photo' => [['file_id' => 'receipt_image_123', 'file_size' => strlen($bytes), 'width' => 10, 'height' => 10]]]));
        if ($process) {
            $draft = DB::table('sales_telegram_checkouts')->where('step', 'proof_checking')->where('thread_id', $this->thread()->id)->orderByDesc('created_at')->first();
            if ($draft) app(Checkout::class)->processProofCheck($draft->id, (int) $draft->version);
        }

    }

    private function receiptFields(array $overrides = []): array
    {
        return array_replace(['readable' => true, 'payment_status' => 'completed', 'amount' => '3000', 'currency' => 'MMK',
            'recipient_account' => '09123456789', 'recipient_name' => 'Shop owner', 'provider' => 'KBZPay',
            'transaction_reference' => 'TG-'.Str::upper(Str::random(12)), 'paid_at' => now()->toIso8601String(), 'notes' => ''], $overrides);
    }

    private function latestButtons(): array
    {
        $outbox = DB::table('sales_telegram_outbox')->whereNotNull('reply_markup')->orderByDesc('id')->firstOrFail();
        return collect(json_decode($outbox->reply_markup, true)['inline_keyboard'])->flatten(1)->pluck('text')->all();
    }

    public function test_payment_method_button_keeps_the_complete_bank_name_and_account_number(): void
    {
        $draft = $this->checkoutDraft('Bank transfer', ['bank' => str_repeat('Bank ', 8).'Wallet', 'account_name' => str_repeat('Account owner ', 5).'Name']);
        $payload = json_decode(Crypt::decryptString($draft->payload_encrypted), true);
        $this->assertSame('bank', $payload['payment_method_id']);
        $this->assertSame('proof', $draft->step);
    }

    public function test_transfer_checkout_waits_for_a_photo_and_submits_order_and_proof_once(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        $this->sendReceipt();
        $this->assertSame('review', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $confirm = $this->button('Confirm order');
        $this->receive($this->update('yes')); // Text still cannot submit.
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        $this->click($confirm); $this->click($confirm);
        $order = DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->sole();
        $this->assertSame('proof_submitted', $order->payment_status);
        $proof = DB::table('ecommerce_payments')->where('order_id', $order->id)->where('type', 'proof')->sole();
        $this->assertSame('pending', $proof->status);
        Storage::disk('local')->assertExists($proof->proof_path);
        $this->assertSame(0, DB::table('ecommerce_payments')->where('order_id', $order->id)->where('type', 'confirm')->count());
        SystemAgent::assertPromptedTimes(1); // The confirmed proof reuses the extraction.
    }

    public function test_receipt_check_runs_after_webhook_and_can_be_recovered_by_the_existing_scheduler(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt(process: false);
        $pending = DB::table('sales_telegram_checkouts')->find($draft->id);
        $this->assertSame('proof_checking', $pending->step);
        SystemAgent::assertPromptedTimes(0);
        $this->assertNotContains('Confirm order', $this->latestButtons());
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        app(Checkout::class)->recoverProofChecks(10);
        $this->assertSame('review', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        (new \App\Jobs\ValidateTelegramCheckoutProof($draft->id, (int) $pending->version))->handle(app(Checkout::class));
        SystemAgent::assertPromptedTimes(1);
        $this->assertContains('Confirm order', $this->latestButtons());
    }

    public static function invalidReceiptFields(): array
    {
        return [
            'amount' => [['amount' => '2999']], 'currency' => [['currency' => 'USD']],
            'recipient' => [['recipient_name' => 'Different owner']], 'account' => [['recipient_account' => '09123450000']],
            'bank' => [['provider' => 'AYA Pay']], 'unreadable' => [['readable' => false]],
            'pending transfer' => [['payment_status' => 'pending']], 'failed transfer' => [['payment_status' => 'failed']],
            'missing reference' => [['transaction_reference' => '']], 'uncertain evidence' => [['notes' => 'Cannot read recipient.']],
        ];
    }

    #[DataProvider('invalidReceiptFields')]
    public function test_receipt_must_pass_every_required_check_before_confirm_is_available(array $fields): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt($fields);
        $row = DB::table('sales_telegram_checkouts')->find($draft->id);
        $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true);
        $this->assertSame('proof', $row->step);
        $this->assertFalse($payload['payment_prevalidation']['passed']);
        $this->assertNotContains('Confirm order', $this->latestButtons());
        $this->assertContains('Check receipt again', $this->latestButtons());
        $this->assertSame('Guest Buyer', $payload['customer_name']);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        $this->assertSame(0, DB::table('ecommerce_stock_reservations')->where('business_id', $this->connection->business_id)->count());
    }

    public function test_receipt_replacement_ignores_the_old_queued_result(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt(process: false);
        $old = DB::table('sales_telegram_checkouts')->find($draft->id);
        $this->sendReceipt(process: false);
        $current = DB::table('sales_telegram_checkouts')->find($draft->id);
        app(Checkout::class)->processProofCheck($old->id, (int) $old->version);
        SystemAgent::assertPromptedTimes(0);
        app(Checkout::class)->processProofCheck($current->id, (int) $current->version);
        SystemAgent::assertPromptedTimes(1);
        $this->assertSame('review', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
    }

    public function test_receipt_checker_failure_keeps_the_draft_and_allows_an_explicit_retry(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt(process: false);
        SystemAgent::fake(fn () => throw new \RuntimeException('Receipt service unavailable'));
        $pending = DB::table('sales_telegram_checkouts')->find($draft->id);
        app(Checkout::class)->processProofCheck($pending->id, (int) $pending->version);
        $failed = DB::table('sales_telegram_checkouts')->find($draft->id);
        $this->assertSame('proof', $failed->step);
        $this->assertNotContains('Confirm order', $this->latestButtons());
        SystemAgent::fake([$this->receiptFields()]);
        $this->click($this->button('Check receipt again'));
        $retry = DB::table('sales_telegram_checkouts')->find($draft->id);
        $this->assertSame('proof_checking', $retry->step);
        app(Checkout::class)->processProofCheck($retry->id, (int) $retry->version);
        $this->assertSame('review', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
    }

    public static function receiptCheckoutChanges(): array
    {
        return ['total' => ['total'], 'cart quantity' => ['cart'], 'receiving account' => ['account']];
    }

    #[DataProvider('receiptCheckoutChanges')]
    public function test_receipt_is_invalidated_by_a_changed_quote_before_confirmation(string $change): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt();
        $confirm = $this->button('Confirm order');
        if ($change === 'total') {
            DB::table('product_prices')->where('business_id', $this->connection->business_id)->where('name', 'Retail')->update(['price' => 2000]);
        } elseif ($change === 'cart') {
            $payload = json_decode(Crypt::decryptString(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('payload_encrypted')), true);
            $payload['items'][0]['quantity'] = 3;
            DB::table('sales_telegram_checkouts')->where('id', $draft->id)->update(['payload_encrypted' => Crypt::encryptString(json_encode($payload))]);
        } else {
            $methods = json_decode(DB::table('storefront_settings')->where('business_id', $this->connection->business_id)->value('payment_methods'), true);
            $methods[1]['account_no'] = '09999990000';
            DB::table('storefront_settings')->where('business_id', $this->connection->business_id)->update(['payment_methods' => json_encode($methods)]);
        }
        $this->click($confirm);
        $this->assertSame('proof', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $this->assertNotContains('Confirm order', $this->latestButtons());
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
    }

    public function test_receipt_expiry_requires_a_new_check_without_losing_the_uploaded_image(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt();
        $confirm = $this->button('Confirm order');
        $this->travel(16)->minutes();
        try {
            $this->click($confirm);
            $row = DB::table('sales_telegram_checkouts')->find($draft->id);
            $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true);
            $this->assertSame('proof', $row->step);
            Storage::disk('local')->assertExists($payload['evidence_path']);
            $this->assertContains('Check receipt again', $this->latestButtons());
            $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        } finally { $this->travelBack(); }
    }

    public function test_receipt_duplicate_claimed_after_prevalidation_is_blocked_before_order_creation(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt();
        $confirm = $this->button('Confirm order');
        $payload = json_decode(Crypt::decryptString(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('payload_encrypted')), true);
        $report = $payload['payment_prevalidation']['report'];
        app(\App\Services\PaymentReceiptClaims::class)->claim($report['transaction_key'], 'shop', 9999999, $report['payment_provider'], $report['observed']['transaction_reference']);
        $this->click($confirm);
        $this->assertNotContains('Confirm order', $this->latestButtons());
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
    }

    public function test_receipt_prevalidation_cannot_be_skipped_or_forged_by_checkout_state(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt();
        $confirm = $this->button('Confirm order');
        $payload = json_decode(Crypt::decryptString(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('payload_encrypted')), true);
        $payload['payment_prevalidation']['token'] = 'forged';
        $payload['payment_prevalidation']['passed'] = true;
        DB::table('sales_telegram_checkouts')->where('id', $draft->id)->update(['payload_encrypted' => Crypt::encryptString(json_encode($payload))]);
        $this->click($confirm);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        $this->assertNotContains('Confirm order', $this->latestButtons());
    }

    public function test_receipt_image_cannot_change_after_it_was_checked(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->sendReceipt();
        $confirm = $this->button('Confirm order');
        $payload = json_decode(Crypt::decryptString(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('payload_encrypted')), true);
        Storage::disk('local')->put($payload['evidence_path'], 'changed image');
        $this->click($confirm);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        $this->assertNotContains('Confirm order', $this->latestButtons());
    }

    public function test_receipt_finalization_failure_rolls_back_the_order_reservation_and_copied_file(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $observed = $this->receiptFields();
        $this->mock(\App\Services\ShopPaymentProofValidation::class, function ($mock) use ($observed) {
            $mock->shouldReceive('extract')->once()->andReturn($observed);
            $mock->shouldReceive('process')->once()->andReturn(['status' => 'failed', 'verdict' => 'needs_review', 'funds_verified' => false]);
        });
        $this->sendReceipt();
        $this->click($this->button('Confirm order'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
        $this->assertSame(0, DB::table('ecommerce_stock_reservations')->where('business_id', $this->connection->business_id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('ecommerce-proofs/'.$this->connection->business_id));
        $payload = json_decode(Crypt::decryptString(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('payload_encrypted')), true);
        Storage::disk('local')->assertExists($payload['evidence_path']);
    }

    public function test_invalid_transfer_screenshot_cannot_advance_or_create_an_order(): void
    {
        $draft = $this->checkoutDraft('Bank transfer');
        $this->receive($this->update('', extra: ['photo' => [['file_id' => '../invalid', 'file_size' => 20]]]));
        $this->assertSame('proof', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->count());
    }

    public function test_existing_guest_order_proof_is_scoped_and_requires_a_signed_customer_confirmation(): void
    {
        $this->checkoutDraft();
        $this->click($this->button('Confirm order'));
        $order = DB::table('ecommerce_orders')->where('business_id', $this->connection->business_id)->sole();
        DB::table('ecommerce_orders')->where('id', $order->id)->update(['payment_method' => json_encode(['id' => 'bank', 'type' => 'transfer', 'provider' => 'manual', 'bank' => 'KBZPay', 'account_name' => 'Shop owner', 'account_no' => '09123456789'])]);
        $this->receive($this->update('Please attach payment to '.$order->reference));
        $thread = app(Identity::class)->context($this->thread());
        $run = DB::table('sales_ai_runs')->where('thread_id', $thread->id)->orderByDesc('created_at')->orderByDesc('trigger_message_id')->firstOrFail();
        DB::table('sales_ai_runs')->where('id', $run->id)->update(['status' => 'processing']);
        $agent = new SalesRepresentative($thread, app(Settings::class)->get($thread->business_id), [], $run->id, $run->trigger_message_id);
        app(PublicTools::class)->handle($agent, 'submit_order_payment_proof', ['reference' => $order->reference]);
        $this->sendReceipt();
        $confirm = $this->button('Submit payment proof');
        $this->click($confirm, 456, 456);
        $this->assertSame(0, DB::table('ecommerce_payments')->where('order_id', $order->id)->count());
        $this->click($confirm); $this->click($confirm);
        $this->assertSame(1, DB::table('ecommerce_payments')->where('order_id', $order->id)->where('type', 'proof')->count());
        $this->assertSame('proof_submitted', DB::table('ecommerce_orders')->where('id', $order->id)->value('payment_status'));
    }

    public function test_guest_checkout_requires_signed_confirmation_and_is_idempotent(): void
    {
        $draft = $this->checkoutDraft();
        $confirm = $this->button('Confirm order');
        $this->receive($this->update('yes please confirm'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $this->click($confirm, 456, 456); // Callback cannot be transferred to another Telegram customer.
        $this->assertNull(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('order_reference'));
        $this->click($confirm);
        $this->click($confirm);
        $order = DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->sole();
        $this->assertNull($order->platform_customer_id);
        $this->assertSame('Guest Buyer', $order->customer_name);
        $this->assertSame(3000, (int) $order->total);
        $this->assertSame('ordered', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $context = app(Identity::class)->context($this->thread());
        $this->assertCount(1, app(Identity::class)->orders($context, 'customer_orders', [])['items']);
        $this->receive($this->update('my orders', 456, 456));
        $this->assertSame([], app(Identity::class)->orders(app(Identity::class)->context($this->thread(456, 456)), 'customer_orders', [])['items']);
    }

    public function test_changed_price_requires_review_and_a_new_confirmation(): void
    {
        $draft = $this->checkoutDraft();
        $old = $this->button('Confirm order');
        $payload = json_decode(Crypt::decryptString($draft->payload_encrypted), true);
        $id = $payload['items'][0]['product_id'];
        $this->putJson('/api/products/'.$id, ['name' => 'Telegram rice', 'price' => 2000, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 2000]]])->assertOk();
        $this->click($old);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $new = $this->button('Confirm order');
        $this->assertNotSame($old, $new);
        $this->click($old);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $this->click($new);
        $this->assertSame(4000, (int) DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->value('total'));
    }

    public function test_optional_account_link_is_one_use_and_unlink_rotates_private_history(): void
    {
        $this->receive($this->update('hello before linking'));
        $old = $this->thread()->id;
        $buyer = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '')->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($buyer['access_token']);
        $web = $this->postJson('/api/ecommerce/stores/'.$this->slug.'/chat')->assertOk()->json('thread.uuid');
        $url = $this->postJson('/api/ecommerce/chat/'.$web.'/telegram-link')->assertOk()->json('url');
        $nonce = substr($url, strpos($url, 'link_') + 5);
        $this->receive($this->update('/start link_'.$nonce));
        $this->assertSame(1, DB::table('sales_telegram_links')->where('connection_id', $this->connection->id)->count());
        $this->receive($this->update('my account orders'));
        $context = app(Identity::class)->context($this->thread());
        $this->assertNotSame($old, $context->id);
        $this->assertSame((int) $buyer['customer']['id'], (int) $context->platform_customer_id);
        $this->receive($this->update('/start link_'.$nonce, 456, 456));
        $this->assertSame(1, DB::table('sales_telegram_links')->where('connection_id', $this->connection->id)->count());
        $this->assertFalse(DB::table('shop_chat_messages')->where('thread_id', $context->id)->where('body', 'like', '%'.$nonce.'%')->exists());
        $this->receive($this->update('/unlink'));
        $this->receive($this->update('hello without account'));
        $this->assertNull(app(Identity::class)->context($this->thread())->platform_customer_id);
        $this->assertNotSame($context->id, $this->thread()->id);
    }

    public function test_connection_revocation_suppresses_pending_delivery_and_multipart_waits(): void
    {
        $this->receive($this->update('hello'));
        $update = DB::table('sales_telegram_updates')->where('connection_id', $this->connection->id)->firstOrFail();
        app(Delivery::class)->notice($update->id, str_repeat('a', 1800), ['inline_keyboard' => [[['text' => 'Review', 'callback_data' => 'fixed']]]]);
        $rows = DB::table('sales_telegram_outbox')->where('update_id', $update->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->reply_markup);
        $this->assertSame(5, app(Delivery::class)->process($rows[1]->id));
        DB::table('sales_telegram_connections')->where('id', $this->connection->id)->increment('version');
        app(Delivery::class)->process($rows[0]->id);
        app(Delivery::class)->process($rows[1]->id);
        $this->assertSame(2, DB::table('sales_telegram_outbox')->whereIn('id', $rows->pluck('id'))->where('status', 'suppressed')->count());
        Http::assertSentCount(3); // Registration only; no customer messages sent.
    }

    public function test_checkout_permission_revocation_cancels_confirmation(): void
    {
        $draft = $this->checkoutDraft();
        $confirm = $this->button('Confirm order');
        $settings = app(Settings::class)->get($this->owner['business']['id']);
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['tool_permissions' => array_values(array_diff($settings['tool_permissions'], ['prepare_checkout']))]))->assertOk();
        $this->click($confirm);
        $this->assertSame('cancelled', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_linked_order_placed_in_telegram_is_not_readable_after_unlink(): void
    {
        $draft = $this->checkoutDraft();
        $buyer = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '')->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        // This fixture represents an already linked private sender before checkout started.
        DB::table('sales_telegram_links')->insert(['connection_id' => $this->connection->id, 'bot_id' => $this->connection->bot_id, 'sender_id' => 123, 'platform_customer_id' => $buyer['customer']['id'], 'created_at' => now(), 'updated_at' => now()]);
        $this->click($this->button('Confirm order'));
        $this->assertSame((int) $buyer['customer']['id'], (int) DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->value('platform_customer_id'));
        $this->assertCount(1, app(Identity::class)->orders(app(Identity::class)->context($this->thread()), 'customer_orders', [])['items']);
        $this->receive($this->update('/unlink'));
        $this->receive($this->update('orders without linking'));
        $this->assertSame([], app(Identity::class)->orders(app(Identity::class)->context($this->thread()), 'customer_orders', [])['items']);
    }

    public function test_group_membership_change_invalidates_previous_runs_even_after_rejoin(): void
    {
        $mention = ['entities' => [['type' => 'mention', 'offset' => 0, 'length' => 17]]];
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $group = DB::table('sales_telegram_groups')->where('connection_id', $this->connection->id)->firstOrFail();
        DB::table('sales_telegram_groups')->where('id', $group->id)->update(['status' => 'approved']);
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $thread = $this->thread(123, -10055);
        $run = DB::table('sales_ai_runs')->where('thread_id', $thread->id)->firstOrFail();
        foreach (['left', 'member'] as $status) {
            $this->receive(['update_id' => ++$this->sequence, 'my_chat_member' => ['chat' => ['id' => -10055, 'type' => 'supergroup', 'title' => 'Fixture group'], 'new_chat_member' => ['user' => ['id' => 990001], 'status' => $status]]]);
        }
        $this->assertFalse(app(Inbox::class)->current($thread, $run->trigger_message_id));
    }

    public function test_encrypted_settings_and_authenticated_idempotent_admission(): void
    {
        $this->assertNotSame($this->token, $this->connection->token_encrypted);
        $public = $this->getJson('/api/shop-admin/sales-ai/telegram')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->token, $public);
        $this->assertStringNotContainsString($this->secret, $public);
        $update = $this->update('Hello');
        $this->postJson('/integrations/telegram/sales-ai/'.$this->connection->uuid, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        $this->receive($update);
        $this->receive($update);
        $this->assertSame(1, DB::table('sales_ai_runs')->where('business_id', $this->owner['business']['id'])->count());
        $this->assertNull($this->thread()->platform_customer_id);
    }

    public function test_staff_cannot_manage_the_business_bot(): void
    {
        DB::table('users')->where('id', $this->owner['user']['id'])->update(['role' => 'staff']);
        $this->getJson('/api/shop-admin/sales-ai/telegram')->assertForbidden();
        $this->postJson('/api/shop-admin/sales-ai/telegram/test')->assertForbidden();
    }

    public function test_group_approval_mentions_and_per_member_history_are_required(): void
    {
        $mention = ['entities' => [['type' => 'mention', 'offset' => 0, 'length' => 17]]];
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->owner['business']['id'])->count());
        $group = DB::table('sales_telegram_groups')->where('connection_id', $this->connection->id)->first();
        $this->putJson('/api/shop-admin/sales-ai/telegram/groups/'.$group->id, ['version' => $group->version, 'chat_id' => $group->chat_id, 'title' => $group->title, 'status' => 'approved', 'reply_mentions' => true, 'reply_to_bot' => true, 'daily_limit' => 100, 'per_user_limit' => 20])->assertOk();
        $this->receive($this->update('not addressed', 123, -10055));
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $this->receive($this->update('@fixture_shop_bot hello', 456, -10055, $mention));
        $this->assertNotSame($this->thread(123, -10055)->id, $this->thread(456, -10055)->id);
        $context = app(Identity::class)->context($this->thread(123, -10055));
        $this->assertFalse(Identity::permits($context, 'customer_orders'));
        $this->assertFalse(Identity::permits($context, 'prepare_checkout'));
    }

    public function test_same_sales_worker_charges_shop_credit_and_delivery_is_not_replayed(): void
    {
        $this->receive($this->update('Hello'));
        SalesRepresentative::fake(['Hello from your shop.']);
        $run = DB::table('sales_ai_runs')->where('thread_id', $this->thread()->id)->first();
        app(Runner::class)->process($run->id);
        $this->assertSame('completed', DB::table('sales_ai_runs')->where('id', $run->id)->value('status'));
        $delivery = DB::table('sales_telegram_outbox')->whereNotNull('message_id')->first();
        $this->assertNotNull($delivery);
        app(Delivery::class)->process($delivery->id);
        app(Delivery::class)->process($delivery->id);
        Http::assertSentCount(5); // getMe, webhook info, registration, typing, one reply.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 123 && $r['text'] === 'Hello from your shop.');
        $this->assertSame(1, DB::table('sales_ai_entries')->where('business_id', $this->owner['business']['id'])->where('type', 'usage')->count());
    }

    public function test_cross_business_bot_claim_and_group_update_are_rejected(): void
    {
        $other = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', 'token')->postJson('/api/auth/register', ['business_name' => 'Other shop', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($other['access_token']);
        $row = $this->getJson('/api/shop-admin/sales-ai/telegram')->assertOk()->json('settings');
        $this->putJson('/api/shop-admin/sales-ai/telegram', ['version' => $row['version'], 'bot_token' => $this->token, 'enabled' => true, 'groups_enabled' => true, 'daily_limit' => 100, 'per_user_limit' => 10])->assertUnprocessable();
        $this->getJson('/api/shop-admin/sales-ai/telegram/groups/999999')->assertNotFound();
    }

    public function test_owner_product_only_message_sends_a_public_url_and_reports_real_delivery(): void
    {
        config(['clients.shop_url' => 'https://shop.fixture.test']);
        $product = $this->postJson('/api/products', ['name' => 'Shared rice', 'price' => 1500, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 1500]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $this->receive($this->update('Show products'));
        $thread = $this->thread();
        $url = '/api/shop-admin/chat/'.$thread->uuid.'/messages';
        $data = ['client_id' => (string) Str::uuid(), 'product_id' => $product['id']];
        $reply = $this->postJson($url, $data)->assertOk()->assertJsonPath('message.telegram_delivery.status', 'pending');
        $id = $reply->json('message.id');
        $public = 'https://shop.fixture.test/#/store/'.$this->slug.'/products/'.$product['id'];
        $reply->assertJsonPath('message.product.public_url', $public);
        $this->postJson($url, $data)->assertOk();
        $this->assertSame(1, DB::table('sales_telegram_outbox')->where('message_id', $id)->count());
        // Reproduce a product-only message saved by the old bridge but never staged.
        DB::table('sales_telegram_outbox')->where('message_id', $id)->delete();
        $legacyProduct = $reply->json('message.product');
        unset($legacyProduct['public_url']);
        DB::table('shop_chat_messages')->where('id', $id)->update(['product_snapshot' => json_encode($legacyProduct)]);
        $this->getJson($url)->assertOk()->assertJsonPath('items.1.telegram_delivery.status', 'not_sent');
        $this->postJson($url.'/'.$id.'/retry-telegram')->assertOk()->assertJsonPath('message.id', $id)->assertJsonPath('message.telegram_delivery.status', 'pending');
        $outbox = DB::table('sales_telegram_outbox')->where('message_id', $id)->firstOrFail();
        app(Delivery::class)->process($outbox->id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && str_contains($request['text'] ?? '', $public) && $request['chat_id'] === 123);
        $this->getJson($url.'?after_id='.$id.'&delivery_ids='.$id)->assertOk()->assertJsonCount(0, 'items')
            ->assertJsonPath('delivery_updates.0.telegram_delivery.status', 'sent')
            ->assertJsonPath('delivery_updates.0.telegram_delivery.read_status_available', false)
            ->assertJsonPath('thread.sales_ai_channel', 'telegram')->assertJsonPath('thread.customer_active', null)->assertJsonPath('thread.customer_last_seen_at', null);
        $this->assertNotNull($this->getJson($url)->json('thread.telegram_last_message_at'));
    }

    public function test_failed_delivery_can_be_retried_once_without_duplicating_the_saved_message(): void
    {
        $this->receive($this->update('Hi'));
        $thread = $this->thread();
        $url = '/api/shop-admin/chat/'.$thread->uuid.'/messages';
        $id = $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Hello'])->assertOk()->json('message.id');
        $outbox = DB::table('sales_telegram_outbox')->where('message_id', $id)->value('id');
        $this->deliveryResponse = ['ok' => false, 'error_code' => 403];
        app(Delivery::class)->process($outbox);
        $this->getJson($url)->assertOk()->assertJsonPath('items.1.telegram_delivery.status', 'failed')->assertJsonPath('items.1.telegram_delivery.can_retry', true);
        $this->postJson($url.'/'.$id.'/retry-telegram')->assertOk()->assertJsonPath('message.telegram_delivery.status', 'pending');
        $this->postJson($url.'/'.$id.'/retry-telegram')->assertConflict();
        $this->deliveryResponse = ['ok' => true, 'result' => ['message_id' => 999]];
        app(Delivery::class)->process($outbox);
        $this->assertSame('sent', app(Delivery::class)->summaries([$id])[$id]['status']);
        $this->assertSame(1, DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('sender', 'owner')->count());
    }

    public function test_unknown_delivery_is_never_retried_and_status_requests_are_thread_scoped(): void
    {
        $this->receive($this->update('First customer'));
        $first = $this->thread();
        $url = '/api/shop-admin/chat/'.$first->uuid.'/messages';
        $id = $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Hello'])->assertOk()->json('message.id');
        $this->deliveryResponse = ['ok' => false, 'error_code' => 502];
        app(Delivery::class)->process(DB::table('sales_telegram_outbox')->where('message_id', $id)->value('id'));
        $this->postJson($url.'/'.$id.'/retry-telegram')->assertConflict();
        $this->assertFalse(app(Delivery::class)->summaries([$id])[$id]['can_retry']);
        $this->assertSame('delivery_unknown', app(Delivery::class)->summaries([$id])[$id]['status']);
        $this->receive($this->update('Second customer', 456, 456));
        $other = '/api/shop-admin/chat/'.$this->thread(456, 456)->uuid.'/messages';
        $this->getJson($other.'?delivery_ids='.$id)->assertOk()->assertJsonCount(0, 'delivery_updates');
        $this->postJson($other.'/'.$id.'/retry-telegram')->assertNotFound();
    }

    public function test_private_image_only_message_is_uploaded_without_a_public_storage_url(): void
    {
        Storage::fake('local');
        $this->receive($this->update('Send a photo'));
        $thread = $this->thread();
        $url = '/api/shop-admin/chat/'.$thread->uuid.'/messages';
        $response = $this->post($url, ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->image('test.jpg', 20, 20)])->assertOk();
        $id = $response->json('message.id');
        $response->assertJsonPath('message.telegram_delivery.status', 'pending');
        $outbox = DB::table('sales_telegram_outbox')->where('message_id', $id)->value('id');
        app(Delivery::class)->process($outbox);
        $this->assertSame('sent', app(Delivery::class)->summaries([$id])[$id]['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto') && $request->hasFile('photo'));
        $this->assertStringNotContainsString('shop-chat-images/', $response->getContent());
    }

    public function test_multipart_delivery_retry_preserves_already_sent_parts(): void
    {
        $this->receive($this->update('Hello'));
        $thread = $this->thread();
        $url = '/api/shop-admin/chat/'.$thread->uuid.'/messages';
        $id = $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => str_repeat('x', 2000)])->assertOk()->json('message.id');
        $parts = DB::table('sales_telegram_outbox')->where('message_id', $id)->orderBy('part')->pluck('id');
        app(Delivery::class)->process($parts[0]);
        $this->deliveryResponse = ['ok' => false, 'error_code' => 400];
        app(Delivery::class)->process($parts[1]);
        $summary = app(Delivery::class)->summaries([$id])[$id];
        $this->assertTrue($summary['partial']);
        $this->assertSame('failed', $summary['status']);
        $this->postJson($url.'/'.$id.'/retry-telegram')->assertOk();
        $this->assertSame('sent', DB::table('sales_telegram_outbox')->where('id', $parts[0])->value('status'));
        $this->assertSame('pending', DB::table('sales_telegram_outbox')->where('id', $parts[1])->value('status'));
    }
}
