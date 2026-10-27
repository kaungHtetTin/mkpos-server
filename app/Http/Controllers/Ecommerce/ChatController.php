<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use App\Services\PlatformCustomerAvatar;
use App\Services\SalesAi\Chat;
use App\Services\SalesAi\CustomerActions;
use App\Services\SalesAi\Settings;
use App\Services\SalesTelegram\Delivery;
use App\Services\ShopChatImages;
use App\Services\ShopChatRealtime;
use App\Services\ShopCustomerAccess;
use App\Support\AppTime;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(private ShopChatRealtime $realtime) {}

    public function config(): array
    {
        return $this->realtime->settings();
    }

    public function ownerConfig(): array
    {
        return $this->config() + ['channel' => 'private-shop.chat.business.'.app(TenantContext::class)->requireId(), 'sales_ai_enabled' => app(Settings::class)->ready() && app(Settings::class)->get(app(TenantContext::class)->requireId())['enabled']];
    }

    public function customerInboxConfig(Request $request): array
    {
        return $this->config() + ['channel' => 'private-shop.chat.customer.'.$request->attributes->get('platform_customer')->id];
    }

    public function customerAppPresence(Request $request): array
    {
        $account = $request->attributes->get('platform_customer');
        DB::table('shop_chat_threads')->where('platform_customer_id', $account->id)->update(['customer_last_seen_at' => now()]);

        return ['ok' => true];
    }

    public function ownerNotifications(Request $request): array
    {
        return $this->notifications($request, true);
    }

    public function customerNotifications(Request $request): array
    {
        return $this->notifications($request, false);
    }

    public function customerIndex(Request $request): array
    {
        abort_unless(config('shop_chat.enabled'), 403, 'Chat is currently unavailable.');
        $data = $request->validate(['offset' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'q' => ['nullable', 'string', 'max:120'], 'unread' => ['nullable', 'boolean']]);
        $limit = (int) ($data['limit'] ?? 24);
        $rows = DB::table('shop_chat_threads as thread')
            ->join('businesses as business', 'business.id', '=', 'thread.business_id')
            ->leftJoin('storefront_settings as store', 'store.business_id', '=', 'thread.business_id')
            ->where('thread.platform_customer_id', $request->attributes->get('platform_customer')->id)
            ->when(trim($data['q'] ?? '') !== '', fn ($query) => $query->where('business.name', 'like', '%'.trim($data['q']).'%'))
            ->when($request->boolean('unread'), fn ($query) => $query->whereExists(fn ($messages) => $messages->selectRaw('1')->from('shop_chat_messages as unread')->whereColumn('unread.thread_id', 'thread.id')->where('unread.sender', 'owner')->whereColumn('unread.id', '>', 'thread.customer_read_id')))
            ->orderByDesc('thread.updated_at')->orderByDesc('thread.id')
            ->offset((int) ($data['offset'] ?? 0))->limit($limit + 1)
            ->get(['thread.*', 'business.name as shop_name', 'store.slug']);
        $items = $rows->take($limit)->map(function ($thread) {
            $messages = DB::table('shop_chat_messages')->where('thread_id', $thread->id);
            $latest = (clone $messages)->orderByDesc('id')->first();

            return ['uuid' => $thread->uuid, 'shop_name' => $thread->shop_name, 'slug' => $thread->slug,
                'owner_active' => $this->view($thread)['owner_active'],
                'updated_at' => AppTime::utc($latest?->created_at ?? $thread->updated_at),
                'product' => $latest?->product_snapshot ? json_decode($latest->product_snapshot, true) : null,
                'preview' => $latest ? mb_substr($latest->body !== '' ? $latest->body : ($latest->product_snapshot ? 'Shared product' : 'Sent an image'), 0, 160) : '',
                'unread_count' => (clone $messages)->where('sender', 'owner')->where('id', '>', $thread->customer_read_id)->count()];
        })->values();

        return ['items' => $items, 'has_more' => $rows->count() > $limit];
    }

    public function customerShow(Request $request, string $uuid): array
    {
        $thread = $this->thread($request, $uuid, false);

        return ['thread' => $this->view($thread) + [
            'shop_name' => DB::table('businesses')->where('id', $thread->business_id)->value('name'),
            'slug' => DB::table('storefront_settings')->where('business_id', $thread->business_id)->value('slug'),
        ]];
    }

    private function notifications(Request $request, bool $staff): array
    {
        if (! config('shop_chat.enabled')) {
            return ['enabled' => false, 'items' => [], 'cursor' => 0, 'unread_count' => 0];
        }
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $query = DB::table('shop_chat_messages as message')->join('shop_chat_threads as thread', 'thread.id', '=', 'message.thread_id')
            ->where('message.sender', $staff ? 'customer' : 'owner');
        if ($staff) {
            $query->where('thread.business_id', app(TenantContext::class)->requireId());
        } else {
            $query->where('thread.platform_customer_id', $request->attributes->get('platform_customer')->id);
        }
        $latest = (int) (clone $query)->max('message.id');
        $readColumn = $staff ? 'thread.owner_read_id' : 'thread.customer_read_id';
        $unread = (clone $query)->whereColumn('message.id', '>', $readColumn)->count();
        $items = [];
        $cursor = $latest;
        if (isset($data['after_id'])) {
            $rows = (clone $query)->where('message.id', '>', $data['after_id'])->orderBy('message.id')->limit(50)
                ->leftJoin('storefront_settings as store', 'store.business_id', '=', 'thread.business_id')
                ->join('businesses as business', 'business.id', '=', 'thread.business_id')
                ->get(['message.id', 'message.body', 'message.product_snapshot', 'thread.uuid', 'thread.customer_name', 'store.slug', 'business.name as shop_name', $readColumn.' as read_id']);
            $cursor = $rows->isEmpty() ? max($latest, (int) $data['after_id']) : (int) $rows->last()->id;
            $items = $rows->filter(fn ($row) => $row->id > $row->read_id)->map(fn ($row) => [
                'id' => (int) $row->id, 'uuid' => $row->uuid, 'name' => $staff ? $row->customer_name : $row->shop_name,
                'slug' => $row->slug, 'preview' => mb_substr($row->body !== '' ? $row->body : ($row->product_snapshot ? 'Shared product: '.(json_decode($row->product_snapshot, true)['name'] ?? 'Product') : 'Sent an image'), 0, 160),
            ])->values();
        }

        return ['enabled' => true, 'items' => $items, 'cursor' => $cursor, 'unread_count' => $unread, 'has_more' => $cursor < $latest];
    }

    public function unread(): array
    {
        $count = DB::table('shop_chat_messages as message')->join('shop_chat_threads as thread', 'thread.id', '=', 'message.thread_id')
            ->where('thread.business_id', app(TenantContext::class)->requireId())->where('message.sender', 'customer')
            ->whereColumn('message.id', '>', 'thread.owner_read_id')->count();

        return ['unread_count' => $count];
    }

    public function start(Request $request, string $slug): array
    {
        abort_unless(config('shop_chat.enabled'), 404);
        $store = app(EcommerceService::class)->store($slug);
        $account = $request->attributes->get('platform_customer');
        abort_unless($account, 401, 'Please sign in to your customer account.');

        return DB::transaction(function () use ($store, $account) {
            DB::table('storefront_settings')->where('id', $store->id)->lockForUpdate()->first();
            $thread = DB::table('shop_chat_threads')->where('business_id', $store->business_id)->where('platform_customer_id', $account->id)->first();
            if ($thread) {
                return ['thread' => $this->view($thread)];
            }
            $phone = $account->phone ?? '';
            abort_if(ShopCustomerAccess::blocked((int) $store->business_id, $phone, 'account:'.$account->id), 403, 'This shop is not accepting new chats from this customer.');
            $id = DB::table('shop_chat_threads')->insertGetId(['uuid' => (string) Str::uuid(), 'business_id' => $store->business_id,
                'platform_customer_id' => $account->id, 'customer_name' => $account->name, 'phone' => $phone,
                'created_at' => now(), 'updated_at' => now()]);

            return ['thread' => $this->view(DB::table('shop_chat_threads')->where('id', $id)->first())];
        });
    }

    private function view(object $thread): array
    {
        $telegram = ($thread->sales_ai_channel ?? 'web') === 'telegram';
        $ownerSeen = DB::table('shop_chat_presence')->where('business_id', $thread->business_id)->value('owner_last_seen_at');
        $active = fn ($value) => $value && Carbon::parse($value)->greaterThan(now()->subSeconds(60));

        return AppTime::shopPayload(['timezone' => DB::table('businesses')->where('id', $thread->business_id)->value('timezone') ?: config('app.timezone'), 'uuid' => $thread->uuid, 'customer_name' => $thread->customer_name, 'phone' => $thread->phone,
            'avatar_url' => PlatformCustomerAvatar::url(property_exists($thread, 'avatar_path') ? $thread->avatar_path : ($thread->platform_customer_id ? DB::table('platform_customers')->where('id', $thread->platform_customer_id)->value('avatar_path') : null)),
            'customer_key' => $thread->platform_customer_id ? 'account:'.$thread->platform_customer_id : null,
            'account_type' => $thread->platform_customer_id ? 'account' : 'guest', 'updated_at' => $thread->updated_at,
            'owner_read_id' => (int) $thread->owner_read_id, 'customer_read_id' => (int) $thread->customer_read_id,
            'sales_ai_channel' => $telegram ? 'telegram' : 'web',
            'telegram_last_message_at' => $telegram ? AppTime::iso(DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('sender', 'customer')->max('created_at')) : null,
            'customer_last_seen_at' => $telegram ? null : $thread->customer_last_seen_at,
            'customer_active' => $telegram ? null : (bool) $active($thread->customer_last_seen_at), 'owner_active' => (bool) $active($ownerSeen),
            'owner_last_seen_at' => $ownerSeen] + app(Chat::class)->view($thread));
    }

    private function messageView(object $message): array
    {
        $data = array_intersect_key((array) $message, array_flip(['id', 'client_id', 'sender', 'body', 'created_at']));
        $data['is_ai'] = (bool) ($message->is_ai ?? false);
        $data['ai_name'] = $data['is_ai'] ? $message->ai_name : null;
        $data['sales_actions'] = $message->sales_actions ?? [];
        $data['sales_content'] = ! empty($message->sales_ai_content) ? json_decode($message->sales_ai_content, true) : null;
        $data['action_event'] = ! empty($message->sales_ai_action_event) ? array_intersect_key(json_decode($message->sales_ai_action_event, true), array_flip(['action_id', 'kind', 'operation', 'outcome'])) : null;
        $data['product'] = $message->product_snapshot ? json_decode($message->product_snapshot, true) : null;
        $data['telegram_delivery'] = $message->telegram_delivery ?? null;
        $data['image'] = $message->image_expires_at ? ['status' => ! $message->image_path || $message->image_deleted_at || now()->greaterThanOrEqualTo($message->image_expires_at) ? 'expired' : 'available',
            'expires_at' => $message->image_expires_at, 'mime' => $message->image_mime] : null;

        return AppTime::shopPayload($data);
    }

    public function customerImage(Request $request, string $uuid, int $id)
    {
        return $this->image($request, $uuid, $id, false);
    }

    public function ownerImage(Request $request, string $uuid, int $id)
    {
        return $this->image($request, $uuid, $id, true);
    }

    private function image(Request $request, string $uuid, int $id, bool $staff)
    {
        $thread = $this->thread($request, $uuid, $staff);
        $message = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('id', $id)->first();
        abort_unless($message && $message->image_expires_at, 404);
        abort_if(! $message->image_path || $message->image_deleted_at || now()->greaterThanOrEqualTo($message->image_expires_at), 410, 'Image expired.');
        abort_unless(Storage::disk('local')->exists($message->image_path), 404, 'Image unavailable.');

        return response()->file(Storage::disk('local')->path($message->image_path), ['Content-Type' => $message->image_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function thread(Request $request, string $uuid, bool $staff): object
    {
        $query = DB::table('shop_chat_threads')->where('uuid', $uuid);
        if ($staff) {
            $query->where('business_id', app(TenantContext::class)->requireId());
        }
        $thread = $query->first();
        abort_unless($thread, 404, 'Conversation unavailable.');
        if (! $staff) {
            $account = $request->attributes->get('platform_customer');
            abort_unless($account, 401, 'Please sign in to your customer account.');
            abort_unless($thread->platform_customer_id && (int) $account->id === (int) $thread->platform_customer_id, 403, 'Conversation unavailable.');
        }

        return $thread;
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['filter' => ['nullable', 'in:all,unread']]);
        $businessId = app(TenantContext::class)->requireId();
        $query = DB::table('shop_chat_threads')->where('business_id', $businessId);
        $unreadOnly = ($data['filter'] ?? 'all') === 'unread';
        if ($unreadOnly) {
            $query->whereExists(fn ($messages) => $messages->selectRaw('1')->from('shop_chat_messages as unread_message')
                ->whereColumn('unread_message.thread_id', 'shop_chat_threads.id')->where('unread_message.sender', 'customer')
                ->whereColumn('unread_message.id', '>', 'shop_chat_threads.owner_read_id'));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($where) => $where->where('customer_name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%'));
        }
        $total = (clone $query)->count();
        $unreadThreads = $unreadOnly ? (clone $query)->pluck('uuid') : null;
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        if ($cursor = $request->query('cursor')) {
            abort_unless(is_string($cursor) && strlen($cursor) <= 512, 422, 'Invalid conversation cursor.');
            $position = json_decode(base64_decode($cursor, true) ?: '', true);
            abort_unless(is_array($position), 422, 'Invalid conversation cursor.');
            $position = validator($position, ['updated_at' => ['required', 'date_format:Y-m-d H:i:s'], 'id' => ['required', 'integer', 'min:1']])->validate();
            $query->where(fn ($where) => $where->where('updated_at', '<', $position['updated_at'])
                ->orWhere(fn ($same) => $same->where('updated_at', $position['updated_at'])->where('id', '<', $position['id'])));
        }
        $batch = $query->orderByDesc('updated_at')->orderByDesc('id')->offset($cursor ? 0 : max(0, (int) $request->query('offset', 0)))->limit($limit + 1)->get();
        $rows = $batch->take($limit);
        $avatars = DB::table('platform_customers')->whereIn('id', $rows->pluck('platform_customer_id')->filter())->pluck('avatar_path', 'id');
        foreach ($rows as $thread) {
            $thread->avatar_path = $avatars[$thread->platform_customer_id] ?? null;
        }
        $latestIds = DB::table('shop_chat_messages')->whereIn('thread_id', $rows->pluck('id'))->groupBy('thread_id')->selectRaw('MAX(id) as id')->pluck('id');
        $latest = DB::table('shop_chat_messages')->whereIn('id', $latestIds)->get()->keyBy('thread_id');
        $telegramThreads = $rows->filter(fn ($thread) => ($thread->sales_ai_channel ?? 'web') === 'telegram')->pluck('id');
        $this->withTelegramDelivery($latest->filter(fn ($message) => $telegramThreads->contains($message->thread_id)));
        $last = $rows->last();

        return ['unread_threads' => $unreadThreads,
            'active_customers' => DB::table('shop_chat_threads')->where('business_id', $businessId)->when(Schema::hasColumn('shop_chat_threads', 'sales_ai_channel'), fn ($query) => $query->where('sales_ai_channel', '!=', 'telegram'))->where('customer_last_seen_at', '>', now()->subSeconds(60))->pluck('uuid'),
            'items' => $rows->map(fn ($thread) => $this->view($thread) + ['id' => $thread->uuid,
                'last_message' => isset($latest[$thread->id]) ? $this->messageView($latest[$thread->id]) : null,
                'unread' => DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('sender', 'customer')->where('id', '>', $thread->owner_read_id)->count()]), 'total' => $total,
            'next_cursor' => $batch->count() > $limit && $last ? base64_encode(json_encode(['updated_at' => $last->updated_at, 'id' => $last->id])) : null,
            'channel' => 'private-shop.chat.business.'.$businessId];
    }

    public function customerMessages(Request $request, string $uuid): array
    {
        return $this->messages($request, $uuid, false);
    }

    public function ownerMessages(Request $request, string $uuid): array
    {
        return $this->messages($request, $uuid, true);
    }

    private function messages(Request $request, string $uuid, bool $staff): array
    {
        $thread = $this->thread($request, $uuid, $staff);
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0'], 'before_id' => ['nullable', 'integer', 'min:1'],
            'delivery_ids' => ['nullable', 'string', 'max:1000', 'regex:/^[0-9]+(?:,[0-9]+)*$/D']]);
        $query = DB::table('shop_chat_messages')->where('thread_id', $thread->id);
        if (! empty($data['after_id'])) {
            $query->where('id', '>', $data['after_id']);
        }
        if (! empty($data['before_id'])) {
            $query->where('id', '<', $data['before_id']);
        }
        $ascending = ! empty($data['after_id']);
        $items = $query->orderBy('id', $ascending ? 'asc' : 'desc')->limit(51)->get();
        $hasMore = $items->count() > 50;
        $items = $items->take(50);
        $actions = app(CustomerActions::class)->summariesFor($items->where('is_ai', true)->pluck('id')->all());
        foreach ($items as $message) {
            $message->sales_actions = $actions[$message->id] ?? [];
        }
        $updates = collect();
        if (($thread->sales_ai_channel ?? 'web') === 'telegram') {
            $this->withTelegramDelivery($items);
            if ($staff && ! empty($data['delivery_ids'])) {
                $ids = array_slice(array_unique(explode(',', $data['delivery_ids'])), 0, 50);
                $updates = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('sender', 'owner')->whereIn('id', $ids)->get();
                $this->withTelegramDelivery($updates);
            }
        }

        return ['thread' => $this->view($thread), 'items' => ($ascending ? $items : $items->reverse())->map(fn ($message) => $this->messageView($message))->values(), 'has_more' => $hasMore,
            'delivery_updates' => $updates->map(fn ($message) => ['id' => $message->id, 'telegram_delivery' => $message->telegram_delivery])->values()];
    }

    private function withTelegramDelivery($messages): void
    {
        $outgoing = $messages->filter(fn ($message) => $message->sender === 'owner');
        $summaries = app(Delivery::class)->summaries($outgoing->pluck('id')->all());
        foreach ($outgoing as $message) {
            $message->telegram_delivery = $summaries[$message->id] ?? null;
        }
    }

    public function ownerRetryTelegram(Request $request, string $uuid, int $id): array
    {
        $thread = $this->thread($request, $uuid, true);
        app(Delivery::class)->retry($thread, $id);
        $message = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('id', $id)->firstOrFail();
        $this->withTelegramDelivery(collect([$message]));

        return ['message' => $this->messageView($message)];
    }

    public function customerSend(Request $request, string $uuid): array
    {
        return $this->send($request, $uuid, false);
    }

    public function ownerSend(Request $request, string $uuid): array
    {
        return $this->send($request, $uuid, true);
    }

    private function productStore(object $thread): object
    {
        $slug = DB::table('storefront_settings')->where('business_id', $thread->business_id)->value('slug');
        abort_unless($slug, 404, 'Shop unavailable.');

        return app(EcommerceService::class)->store($slug);
    }

    public function ownerProducts(Request $request, string $uuid): array
    {
        return $this->products($request, $uuid, true);
    }

    public function customerProducts(Request $request, string $uuid): array
    {
        return $this->products($request, $uuid, false);
    }

    private function products(Request $request, string $uuid, bool $staff): array
    {
        $thread = $this->thread($request, $uuid, $staff);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'after_id' => ['nullable', 'integer', 'min:0']]);
        $store = $this->productStore($thread);
        $commerce = app(EcommerceService::class);
        $query = $commerce->catalogQuery($store)->where('p.id', '>', $data['after_id'] ?? 0);
        if ($q = trim($data['q'] ?? '')) {
            $query->where('p.name', 'like', '%'.$q.'%');
        }
        $rows = $query->orderBy('p.id')->limit(21)->get();
        $items = $rows->take(20)->map(fn ($product) => $commerce->productView($store, $product))->values();

        return ['items' => $items, 'next_cursor' => $rows->count() > 20 ? $items->last()['id'] : null];
    }

    private function productSnapshot(object $thread, int $productId): array
    {
        $store = $this->productStore($thread);
        $commerce = app(EcommerceService::class);
        $product = $commerce->catalogQuery($store)->where('p.id', $productId)->first();
        abort_unless($product, 422, 'This product is not available in this shop. Choose another published product.');

        $snapshot = array_intersect_key($commerce->productView($store, $product), array_flip(['id', 'name', 'price', 'currency', 'unit_name', 'photo_url', 'store_slug']));

        return $snapshot + ['public_url' => app(Delivery::class)->productUrl($snapshot)];
    }

    private function send(Request $request, string $uuid, bool $staff): array
    {
        abort_unless(config('shop_chat.enabled'), 403, 'Chat is currently unavailable.');
        $thread = $this->thread($request, $uuid, $staff);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:2000'], 'client_id' => ['required', 'uuid'],
            'product_id' => ['nullable', 'integer', 'min:1'], 'language' => ['sometimes', 'nullable', 'in:en,my'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096']]);
        $language = $data['language'] ?? null;
        $file = $request->file('image');
        $data = ['body' => trim($data['body'] ?? ''), 'client_id' => $data['client_id'], 'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null];
        abort_unless($data['body'] !== '' || $file || $data['product_id'], 422, 'Enter a message or attach an image or product.');
        $hash = $file ? hash_file('sha256', $file->getRealPath()) : null;
        $storedPath = null;
        try {
            $message = DB::transaction(function () use ($thread, $data, $staff, $file, $hash, $language, &$storedPath) {
                $currentThread = DB::table('shop_chat_threads')->where('id', $thread->id)->lockForUpdate()->first();
                $existing = DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('client_id', $data['client_id'])->first();
                if ($existing) {
                    abort_unless($existing->sender === ($staff ? 'owner' : 'customer') && $existing->body === $data['body'] && $existing->image_hash === $hash && ($existing->product_id === null ? null : (int) $existing->product_id) === $data['product_id'], 409, 'This message key was already used.');

                    return $existing;
                }
                abort_if(! $staff && ShopCustomerAccess::blocked((int) $thread->business_id, $thread->phone, $thread->platform_customer_id ? 'account:'.$thread->platform_customer_id : null), 403, 'This shop is not accepting new messages from this customer.');
                $image = [];
                if ($data['product_id']) {
                    $data['product_snapshot'] = json_encode($this->productSnapshot($thread, $data['product_id']), JSON_UNESCAPED_UNICODE);
                }
                if ($file) {
                    $storedPath = app(ShopChatImages::class)->store($file, (int) $thread->business_id);
                    abort_unless($storedPath, 503, 'Unable to save the image. Please try again.');
                    $image = ['image_path' => $storedPath, 'image_mime' => 'image/webp', 'image_hash' => $hash, 'image_expires_at' => now()->addDays(14)];
                }
                $id = DB::table('shop_chat_messages')->insertGetId($data + $image + ['thread_id' => $thread->id, 'sender' => $staff ? 'owner' : 'customer', 'created_at' => now()]);
                DB::table('shop_chat_threads')->where('id', $thread->id)->update(['updated_at' => now()]);
                $saved = DB::table('shop_chat_messages')->where('id', $id)->first();
                if (app(Settings::class)->ready()) {
                    if ($staff) {
                        DB::table('shop_chat_threads')->where('id', $thread->id)->update(['sales_ai_mode' => 'human', 'sales_ai_version' => $currentThread->sales_ai_version + 1, 'sales_ai_handoff_reason' => null]);
                    } else {
                        app(Chat::class)->enqueue($currentThread, $saved, $language);
                    }
                }

                return $saved;
            });
        } catch (\Throwable $error) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            } throw $error;
        }
        app(Delivery::class)->stage($thread, (int) $message->id);
        if (($thread->sales_ai_channel ?? 'web') === 'telegram') {
            $this->withTelegramDelivery(collect([$message]));
        }
        $this->realtime->changed($thread, (int) $message->id);

        return ['message' => $this->messageView($message), 'thread' => $this->view(DB::table('shop_chat_threads')->where('id', $thread->id)->first())];
    }

    public function customerHandoff(Request $request, string $uuid): array
    {
        $thread = $this->thread($request, $uuid, false);

        return ['thread' => $this->view(app(Chat::class)->handoff($thread, 'waiting_owner'))];
    }

    public function ownerHandoffAll(Request $request): array
    {
        $businessId = app(TenantContext::class)->requireId();
        $data = $request->validate(['selected_uuid' => ['nullable', 'uuid']]);
        $selected = empty($data['selected_uuid']) ? null : DB::table('shop_chat_threads')->where('business_id', $businessId)->where('uuid', $data['selected_uuid'])->firstOrFail();
        $count = app(Chat::class)->handoffAll($businessId);

        return ['updated_count' => $count, 'thread' => $selected ? $this->view(DB::table('shop_chat_threads')->where('id', $selected->id)->first()) : null];
    }

    public function ownerHandoff(Request $request, string $uuid): array
    {
        $thread = $this->thread($request, $uuid, true);
        $data = $request->validate(['mode' => ['required', 'in:ai,human'], 'version' => ['required', 'integer', 'min:1']]);

        return ['thread' => $this->view(app(Chat::class)->handoff($thread, $data['mode'], (int) $data['version']))];
    }

    public function ownerRead(Request $request, string $uuid): array
    {
        return $this->read($request, $uuid, true);
    }

    public function ownerPresence(): array
    {
        DB::table('shop_chat_presence')->updateOrInsert(['business_id' => app(TenantContext::class)->requireId()], ['owner_last_seen_at' => now()]);

        return ['ok' => true];
    }

    public function customerPresence(Request $request, string $uuid): array
    {
        $thread = $this->thread($request, $uuid, false);
        DB::table('shop_chat_threads')->where('id', $thread->id)->update(['customer_last_seen_at' => now()]);

        return ['ok' => true];
    }

    public function customerRead(Request $request, string $uuid): array
    {
        return $this->read($request, $uuid, false);
    }

    private function read(Request $request, string $uuid, bool $staff): array
    {
        $thread = $this->thread($request, $uuid, $staff);
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);
        abort_unless(DB::table('shop_chat_messages')->where('thread_id', $thread->id)->where('id', $data['message_id'])->exists(), 422);
        $column = $staff ? 'owner_read_id' : 'customer_read_id';
        $changed = DB::table('shop_chat_threads')->where('id', $thread->id)->where($column, '<', $data['message_id'])->update([$column => $data['message_id']]);
        if ($changed) {
            $this->realtime->changed($thread, (int) $data['message_id']);
        }

        return ['ok' => true];
    }

    public function customerAuth(Request $request): array
    {
        return $this->authorizeChannel($request, false);
    }

    public function ownerAuth(Request $request): array
    {
        return $this->authorizeChannel($request, true);
    }

    private function authorizeChannel(Request $request, bool $staff): array
    {
        $data = $request->validate(['channel_name' => ['required', 'string', 'max:150']]);
        $channel = $data['channel_name'];
        if (! $staff && $channel === 'private-shop.chat.customer.'.$request->attributes->get('platform_customer')->id) {
            return $this->realtime->auth($channel, ['role' => 'customer', 'customer_id' => (int) $request->attributes->get('platform_customer')->id]);
        }
        if ($staff && $channel === 'private-shop.chat.business.'.app(TenantContext::class)->requireId()) {
            return $this->realtime->auth($channel, ['role' => 'owner', 'business_id' => app(TenantContext::class)->requireId()]);
        }
        abort_unless(preg_match('/^private-shop\.chat\.thread\.([a-f0-9-]{36})$/', $channel, $matches), 403);
        $thread = $this->thread($request, $matches[1], $staff);

        return $this->realtime->auth($channel, ['role' => $staff ? 'owner' : 'customer', 'business_id' => (int) $thread->business_id]);
    }
}
