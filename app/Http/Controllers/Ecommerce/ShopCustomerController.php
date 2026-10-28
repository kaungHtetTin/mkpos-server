<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use App\Services\ShopCustomerAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopCustomerController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    // Guests are checkout contacts, not verified accounts. Never merge them into accounts.
    private function identity(): string
    {
        $phone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
        return "CASE WHEN platform_customer_id IS NOT NULL THEN CONCAT('account:', platform_customer_id) ELSE CONCAT('guest:', SHA2($phone, 256)) END";
    }

    private function purchases()
    {
        return DB::table('ecommerce_orders')->where('business_id', $this->tenant->requireId())->where('status', 'completed');
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'account' => ['nullable', 'in:all,guest,account'], 'sort' => ['nullable', 'in:recent,name,purchases'], 'block_status' => ['nullable', 'in:all,blocked,not_blocked']]);
        $identity = $this->identity();
        $groups = $this->purchases()->selectRaw("$identity AS customer_key, COUNT(*) AS purchase_count, MAX(id) AS latest_order_id, MAX(created_at) AS last_purchase_at")->groupByRaw($identity);
        $query = DB::query()->fromSub($groups, 'buyers')->join('ecommerce_orders as latest', 'latest.id', '=', 'buyers.latest_order_id')->where('latest.business_id', $this->tenant->requireId());
        if ($q = trim($data['q'] ?? '')) $query->where(fn ($where) => $where->where('latest.customer_name', 'like', '%'.$q.'%')->orWhere('latest.phone', 'like', '%'.$q.'%')->orWhere('latest.email', 'like', '%'.$q.'%'));
        if (($data['account'] ?? 'all') === 'guest') $query->whereNull('latest.platform_customer_id');
        if (($data['account'] ?? 'all') === 'account') $query->whereNotNull('latest.platform_customer_id');
        if (in_array($data['block_status'] ?? 'all', ['blocked', 'not_blocked'], true)) {
            $query->whereExists(function ($block) {
                $block->selectRaw('1')->from('shop_customer_controls as control')
                    ->whereColumn('control.business_id', 'latest.business_id')->where('control.blocked', true)
                    ->where(fn ($where) => $where->whereColumn('control.customer_key', 'buyers.customer_key')
                        ->orWhere(fn ($phone) => $phone->where('control.phone', '<>', '')
                            ->whereRaw("control.phone = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(latest.phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')")));
            }, 'and', $data['block_status'] === 'not_blocked');
        }
        $total = (clone $query)->count();
        $sort = $data['sort'] ?? 'recent';
        if ($sort === 'name') $query->orderBy('latest.customer_name');
        elseif ($sort === 'purchases') $query->orderByDesc('buyers.purchase_count');
        else $query->orderByDesc('buyers.last_purchase_at');
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $rows = $query->orderBy('buyers.customer_key')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit)
            ->get(['buyers.customer_key as id', 'buyers.customer_key', 'buyers.purchase_count', 'buyers.last_purchase_at', 'latest.customer_name', 'latest.phone', 'latest.email', 'latest.address', 'latest.platform_customer_id']);
        $phones = $rows->map(fn ($row) => ShopCustomerAccess::phone($row->phone))->filter()->unique()->values();
        $blocks = DB::table('shop_customer_controls')->where('business_id', $this->tenant->requireId())->where('blocked', true)
            ->where(fn ($where) => $where->whereIn('customer_key', $rows->pluck('customer_key'))->orWhereIn('phone', $phones))->get(['customer_key', 'phone']);
        $blockedKeys = $blocks->pluck('customer_key')->flip();
        $blockedPhones = $blocks->pluck('phone')->filter()->flip();
        $spending = $this->purchases()->whereIn(DB::raw($identity), $rows->pluck('customer_key'))
            ->selectRaw("$identity AS customer_key, currency, COUNT(*) AS purchase_count, SUM(total) AS completed_spend")
            ->groupByRaw("$identity, currency")->orderBy('currency')->get()->groupBy('customer_key');
        $avatars = DB::table('platform_customers')->whereIn('id', $rows->pluck('platform_customer_id')->filter())->pluck('avatar_path', 'id');
        foreach ($rows as $row) {
            $row->purchase_count = (int) $row->purchase_count;
            $row->account_type = $row->platform_customer_id === null ? 'guest' : 'account';
            $row->avatar_url = \App\Services\PlatformCustomerAvatar::url($avatars[$row->platform_customer_id] ?? null);
            $row->blocked = $blockedKeys->has($row->customer_key) || $blockedPhones->has(ShopCustomerAccess::phone($row->phone));
            $row->spending = ($spending[$row->customer_key] ?? collect())->map(fn ($amount) => [
                'currency' => $amount->currency, 'purchase_count' => (int) $amount->purchase_count,
                'completed_spend' => (int) $amount->completed_spend,
            ])->values();
            unset($row->platform_customer_id);
        }
        return ['items' => \App\Support\AppTime::shopPayload($rows), 'total' => $total];
    }

    public function orders(Request $request, string $customerKey): array
    {
        abort_unless(preg_match('/^(account:[1-9][0-9]*|guest:[a-f0-9]{64})$/', $customerKey), 404);
        $query = $this->purchases()->whereRaw($this->identity().' = ?', [$customerKey]);
        $total = (clone $query)->count();
        abort_unless($total, 404, 'Shop customer unavailable.');
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $rows = $query->orderByDesc('id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit)->get(['id', 'reference', 'created_at', 'currency', 'total', 'status', 'payment_status']);
        return ['items' => \App\Support\AppTime::shopPayload($rows), 'total' => $total];
    }

    private function customer(string $customerKey): object
    {
        abort_unless(preg_match('/^(account:[1-9][0-9]*|guest:[a-f0-9]{64})$/', $customerKey), 404);
        $customer = $this->historyQuery($customerKey)->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")->orderByDesc('id')
            ->first(['customer_name', 'phone', 'email', 'address', 'platform_customer_id']);
        if (! $customer && str_starts_with($customerKey, 'account:')) {
            $customer = DB::table('shop_chat_threads as thread')->join('platform_customers as account', 'account.id', '=', 'thread.platform_customer_id')
                ->where('thread.business_id', $this->tenant->requireId())->where('account.id', (int) substr($customerKey, 8))
                ->first(['account.name as customer_name', 'account.phone', 'account.email', 'account.address', 'thread.platform_customer_id']);
        }
        abort_unless($customer, 404, 'Shop customer unavailable.');
        foreach (['phone', 'email', 'address'] as $field) $customer->$field = (string) ($customer->$field ?? '');
        $customer->customer_key = $customerKey;
        $customer->account_type = $customer->platform_customer_id === null ? 'guest' : 'account';
        $customer->avatar_url = \App\Services\PlatformCustomerAvatar::url($customer->platform_customer_id ? DB::table('platform_customers')->where('id', $customer->platform_customer_id)->value('avatar_path') : null);
        unset($customer->platform_customer_id);
        $customer->blocked = ShopCustomerAccess::blocked($this->tenant->requireId(), $customer->phone, $customerKey);
        $customer->block_reason = DB::table('shop_customer_controls')->where('business_id', $this->tenant->requireId())
            ->where('blocked', true)->where(fn ($where) => $where->where('customer_key', $customerKey)
                ->when(ShopCustomerAccess::phone($customer->phone) !== '', fn ($where) => $where->orWhere('phone', ShopCustomerAccess::phone($customer->phone))))
            ->value('block_reason');
        $customer->credit_customer_id = DB::table('shop_customer_controls as control')->join('customers as contact', 'contact.id', '=', 'control.credit_customer_id')
            ->where('control.business_id', $this->tenant->requireId())->where('contact.business_id', $this->tenant->requireId())
            ->where('control.customer_key', $customerKey)->where('contact.is_active', true)->value('contact.id');
        return $customer;
    }

    public function chat(string $customerKey): array
    {
        abort_unless(config('shop_chat.enabled'), 403, 'Chat is currently unavailable.');
        $customer = $this->customer($customerKey);
        abort_unless($customer->account_type === 'account', 404, 'Chat requires a platform account.');
        $businessId = $this->tenant->requireId();
        $accountId = (int) substr($customerKey, 8);
        return DB::transaction(function () use ($businessId, $accountId, $customer) {
            // Serialize creation with customer-initiated chat to reuse the same conversation.
            DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first();
            $thread = DB::table('shop_chat_threads')->where('business_id', $businessId)->where('platform_customer_id', $accountId)->first();
            if (! $thread) {
                $account = DB::table('platform_customers')->where('id', $accountId)->first();
                abort_unless($account, 404, 'Platform account unavailable.');
                $uuid = (string) \Illuminate\Support\Str::uuid();
                DB::table('shop_chat_threads')->insert(['uuid' => $uuid, 'business_id' => $businessId,
                    'platform_customer_id' => $accountId, 'customer_name' => $account->name,
                    'phone' => $account->phone ?? $customer->phone, ...\App\Support\ChatTime::thread(true, false)]);
            }
            return ['thread' => ['uuid' => $thread->uuid ?? $uuid]];
        });
    }

    public function block(Request $request, string $customerKey): array
    {
        $data = $request->validate(['blocked' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:200']]);
        return DB::transaction(function () use ($customerKey, $data) {
            $businessId = $this->tenant->requireId();
            DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first();
            $customer = $this->customer($customerKey);
            $phone = ShopCustomerAccess::phone($customer->phone);
            // Unblocking also removes a block recorded under the same checkout phone.
            if (! $data['blocked']) DB::table('shop_customer_controls')->where('business_id', $businessId)
                ->where(fn ($query) => $query->where('customer_key', $customerKey)->when($phone !== '', fn ($q) => $q->orWhere('phone', $phone)))
                ->update(['blocked' => false, 'block_reason' => null, 'updated_at' => now()]);
            DB::table('shop_customer_controls')->updateOrInsert(['business_id' => $businessId, 'customer_key' => $customerKey],
                ['phone' => $phone, 'blocked' => (bool) $data['blocked'], 'block_reason' => $data['blocked'] ? trim($data['reason'] ?? '') ?: null : null, 'updated_at' => now()]);
            return ['customer' => $this->customer($customerKey)];
        });
    }

    public function creditContact(Request $request, string $customerKey): array
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer', 'min:1'], 'create_new' => ['sometimes', 'boolean']]);
        abort_if(! empty($data['contact_id']) && ! empty($data['create_new']), 422, 'Choose an existing contact or create a new one.');
        return DB::transaction(function () use ($customerKey, $data) {
            $businessId = $this->tenant->requireId();
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $customer = $this->customer($customerKey);
            $contactId = $customer->credit_customer_id;
            abort_if($contactId && ! empty($data['contact_id']) && (int) $contactId !== (int) $data['contact_id'], 409, 'This buyer is already linked to a credit contact.');
            if (! $contactId && ! empty($data['contact_id'])) {
                $contactId = DB::table('customers')->where('business_id', $businessId)->where('is_active', true)->where('id', $data['contact_id'])->value('id');
                abort_unless($contactId, 404, 'Credit contact unavailable.');
            }
            $phone = ShopCustomerAccess::phone($customer->phone);
            $email = strtolower(trim($customer->email ?? ''));
            if (! $contactId && empty($data['create_new']) && ($phone !== '' || $email !== '')) {
                $matches = DB::table('customers')->where('business_id', $businessId)->where('is_active', true)
                    ->where(function ($query) use ($phone, $email) {
                        if ($phone !== '') $query->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?", [$phone]);
                        if ($email !== '') $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                    })->pluck('id');
                abort_if($matches->count() > 1, 409, 'Multiple POS customers match this phone or email. Resolve the duplicate contacts in Customers before adding this buyer.');
                $contactId = $matches->first();
            }
            if (! $contactId) $contactId = DB::table('customers')->insertGetId(['business_id' => $businessId,
                'name' => $customer->customer_name, 'phone' => $customer->phone, 'email' => $email ?: null, 'address' => $customer->address,
                'note' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shop_customer_controls')->updateOrInsert(['business_id' => $businessId, 'customer_key' => $customerKey],
                ['credit_customer_id' => $contactId, 'updated_at' => now()]);
            return ['customer' => $this->customer($customerKey)];
        });
    }

    public function creditOptions(string $customerKey): array
    {
        $buyer = $this->customer($customerKey);
        $phone = ShopCustomerAccess::phone($buyer->phone);
        $email = strtolower(trim($buyer->email));
        $matches = DB::table('customers')->where('business_id', $this->tenant->requireId())->where('is_active', true)
            ->where(function ($query) use ($phone, $email) {
                $query->whereRaw('1 = 0');
                if ($phone !== '') $query->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?", [$phone]);
                if ($email !== '') $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
            })->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'email']);
        return ['matches' => $matches];
    }

    private function historyQuery(string $customerKey)
    {
        return DB::table('ecommerce_orders')->where('business_id', $this->tenant->requireId())->whereRaw($this->identity().' = ?', [$customerKey]);
    }

    public function show(string $customerKey): array
    {
        $customer = $this->customer($customerKey);
        $query = $this->historyQuery($customerKey);
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) as orders')->groupBy('status')->get();
        $range = (clone $query)->where('status', 'completed')->selectRaw('MIN(created_at) as first_purchase_at, MAX(created_at) as last_purchase_at')->first();
        $money = (clone $query)->select('currency')->selectRaw("SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as completed_spend,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as purchase_count,
            SUM(CASE WHEN payment_status = 'refunded' THEN total ELSE 0 END) as refunded,
            SUM(CASE WHEN payment_status = 'refund_pending' THEN total ELSE 0 END) as refund_pending")
            ->groupBy('currency')->orderBy('currency')->get();
        foreach ($money as $row) {
            foreach (['completed_spend', 'purchase_count', 'refunded', 'refund_pending'] as $key) $row->$key = (int) $row->$key;
            $row->average_purchase = $row->purchase_count ? round($row->completed_spend / $row->purchase_count, 2) : 0;
        }
        $states = $counts->pluck('orders', 'status');
        return ['customer' => $customer, 'report' => [
            'total_orders' => (int) $counts->sum('orders'), 'completed_orders' => (int) ($states['completed'] ?? 0),
            'active_orders' => (int) $counts->whereNotIn('status', ['completed', 'cancelled', 'expired'])->sum('orders'),
            'cancelled_orders' => (int) ($states['cancelled'] ?? 0), 'expired_orders' => (int) ($states['expired'] ?? 0),
            'first_purchase_at' => \App\Support\AppTime::utc($range->first_purchase_at), 'last_purchase_at' => \App\Support\AppTime::utc($range->last_purchase_at),
            'currencies' => $money,
        ]];
    }

    public function history(Request $request, string $customerKey): array
    {
        $this->customer($customerKey);
        $rules = ['q' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:pending,accepted,packing,ready_for_pickup,dispatched,return_pending,completed,cancelled,expired'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']];
        if ($request->filled('date_from')) $rules['date_to'][] = 'after_or_equal:date_from';
        $data = $request->validate($rules);
        $query = $this->historyQuery($customerKey);
        if (! empty($data['date_from']) || ! empty($data['date_to'])) {
            $timezone = DB::table('businesses')->where('id', $this->tenant->requireId())->value('timezone') ?: 'Asia/Rangoon';
            if (! empty($data['date_from'])) $query->where('created_at', '>=', \App\Support\AppTime::day($data['date_from'], $timezone));
            if (! empty($data['date_to'])) $query->where('created_at', '<', \App\Support\AppTime::storage(CarbonImmutable::createFromFormat('!Y-m-d', $data['date_to'], $timezone)->addDay()));
        }
        if ($q = trim($data['q'] ?? '')) $query->where('reference', 'like', '%'.$q.'%');
        if (! empty($data['status'])) $query->where('status', $data['status']);
        $total = (clone $query)->count();
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $rows = $query->orderByDesc('id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit)
            ->get(['id', 'reference', 'customer_name', 'created_at', 'fulfillment', 'currency', 'total', 'status', 'payment_status']);
        return ['total' => $total, 'items' => app(\App\Services\ShopOrderCards::class)->enrich($rows, $this->tenant->requireId())];
    }
}
