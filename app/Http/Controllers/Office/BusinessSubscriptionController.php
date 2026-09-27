<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use App\Services\OfficeBusinessIndex;
use App\Services\OfficeBusinessDeletion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class BusinessSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request): array
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:200'],
            'access' => ['sometimes', Rule::in(['all', 'trial', 'paid', 'expired', 'cancelled', 'scheduled', 'none'])],
            'prospect' => ['sometimes', Rule::in(['all', 'requested', 'engaged', 'setup', 'early', 'paid'])],
            'sort' => ['sometimes', Rule::in(['newest', 'potential', 'activity'])],
            'deletion_candidate' => ['sometimes', Rule::in(['all', 'suggested'])],
        ]);
        $all = DB::query()->fromSub(app(OfficeBusinessIndex::class)->query(), 'business_index');
        $counts = (clone $all)->select('access_state')->selectRaw('COUNT(*) as total')->groupBy('access_state')->pluck('total', 'access_state')->map(fn ($value) => (int) $value)->all();
        $query = clone $all;
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($filter) use ($search) {
                $like = "%{$search}%";
                $filter->where('name', 'like', $like)->orWhere('owner_email', 'like', $like)->orWhere('owner_name', 'like', $like);
            });
        }
        foreach (['access' => 'access_state', 'prospect' => 'prospect'] as $parameter => $column) {
            if ($request->query($parameter, 'all') !== 'all') $query->where($column, $request->query($parameter));
        }
        if ($request->query('deletion_candidate', 'all') === 'suggested') {
            $cutoff = now()->subDays(30);
            $query->where('business_index.created_at', '<', $cutoff)
                ->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')->from('business_subscriptions')
                        ->whereColumn('business_subscriptions.business_id', 'business_index.id');
                });
            foreach (['sales', 'purchases', 'customer_payments', 'supplier_payments', 'expenses', 'stock_movements'] as $table) {
                $query->whereNotExists(function ($subquery) use ($table, $cutoff) {
                    $subquery->selectRaw('1')->from($table)
                        ->whereColumn("{$table}.business_id", 'business_index.id')
                        ->where("{$table}.created_at", '>=', $cutoff);
                });
            }
        }
        $total = (clone $query)->count();
        $perPage = (int) $request->query('per_page', 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) $request->query('page', 1), $lastPage);
        if ($request->query('sort') === 'potential') {
            $query->orderByRaw("CASE prospect WHEN 'requested' THEN 0 WHEN 'engaged' THEN 1 WHEN 'setup' THEN 2 WHEN 'early' THEN 3 ELSE 4 END")->orderByDesc('sales_30d');
        } elseif ($request->query('sort') === 'activity') {
            $query->orderByDesc('last_sale_at');
        }
        $items = $query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(function ($business) {
            $item = (array) $business;
            $item['subscription'] = $this->subscriptions->status((int) $business->id);

            return $item;
        })->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage,
            'summary' => ['total' => array_sum($counts), 'access_counts' => $counts],
            'activity_window_days' => 30, 'as_of' => now()->utc()->toISOString()];
    }

    public function destroyBusinesses(Request $request, OfficeBusinessDeletion $deletion): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'confirmation' => ['required', Rule::in(['DELETE'])],
        ]);

        return $deletion->delete($data['ids']);
    }

    public function show(int $businessId): array
    {
        $business = DB::table('businesses')->leftJoin('users', function ($join) {
            $join->on('users.business_id', '=', 'businesses.id')->where('users.role', 'owner');
        })->where('businesses.id', $businessId)
            ->select(
                'businesses.*',
                'users.id as owner_id',
                'users.name as owner_name',
                'users.email as owner_email',
                'users.is_active as owner_is_active'
            )->first();
        abort_if(! $business, 404, 'Business not found');

        $history = DB::table('business_subscriptions as subscriptions')
            ->join('subscription_plans as plans', 'plans.id', '=', 'subscriptions.subscription_plan_id')
            ->leftJoin('platform_admins as admins', 'admins.id', '=', 'subscriptions.created_by_admin_id')
            ->where('subscriptions.business_id', $businessId)
            ->select(
                'subscriptions.*',
                'plans.name as plan_name',
                'plans.currency',
                'plans.duration_days',
                'admins.name as created_by_name'
            )->orderByDesc('subscriptions.starts_at')->orderByDesc('subscriptions.id')->get()
            ->map(function ($item) {
                $result = (array) $item;
                $result['billing_status'] = $item->status === 'active' && $item->ends_at && Carbon::parse($item->ends_at)->isPast()
                    ? 'expired'
                    : $item->status;

                return $result;
            })->all();

        return [
            'business' => (array) $business,
            'billing' => $this->subscriptions->status($businessId),
            'business_summary' => $this->businessSummary($businessId),
            'billing_history' => $history,
        ];
    }

    private function businessSummary(int $businessId): array
    {
        $products = DB::table('products')->where('business_id', $businessId)->where('is_active', true);
        $productSummary = (clone $products)->selectRaw(
            'COUNT(*) as product_count, COALESCE(SUM(stock), 0) as stock_units, COALESCE(SUM(stock * cost), 0) as inventory_cost'
        )->first();

        $sales = DB::table('sales')->where('business_id', $businessId)->where('status', 'completed');
        $saleSummary = (clone $sales)->selectRaw(
            'COUNT(*) as sale_count, COALESCE(SUM(total), 0) as sales_total, COALESCE(SUM(credit_amount), 0) as credit_total, MAX(created_at) as latest_at'
        )->first();

        $purchases = DB::table('purchases')->where('business_id', $businessId)->where('status', 'completed');
        $purchaseSummary = (clone $purchases)->selectRaw(
            'COUNT(*) as purchase_count, COALESCE(SUM(total_cost), 0) as purchase_total, MAX(created_at) as latest_at'
        )->first();

        $payments = DB::table('customer_payments')->where('business_id', $businessId)->where('status', 'completed');
        $paymentSummary = (clone $payments)->selectRaw(
            "COUNT(*) as payment_count, COALESCE(SUM(CASE WHEN direction = 'customer_to_shop' THEN amount ELSE -amount END), 0) as net_collected, MAX(created_at) as latest_at"
        )->first();

        $supplierPayments = DB::table('supplier_payments')->where('business_id', $businessId)->where('status', 'completed');
        $supplierPaymentSummary = (clone $supplierPayments)->selectRaw(
            "COUNT(*) as payment_count, COALESCE(SUM(CASE WHEN direction = 'shop_to_supplier' THEN amount ELSE -amount END), 0) as settled_total, MAX(created_at) as latest_at"
        )->first();

        $expenses = DB::table('expenses')->where('business_id', $businessId)->where('status', 'completed');
        $expenseSummary = (clone $expenses)->selectRaw(
            'COUNT(*) as expense_count, COALESCE(SUM(amount), 0) as expense_total, MAX(created_at) as latest_at'
        )->first();

        $lastActivity = collect([
            $saleSummary->latest_at,
            $purchaseSummary->latest_at,
            $paymentSummary->latest_at,
            $supplierPaymentSummary->latest_at,
            $expenseSummary->latest_at,
        ])->filter()->map(fn ($value) => Carbon::parse($value))->sortDesc()->first();

        return [
            'customer_count' => DB::table('customers')->where('business_id', $businessId)->where('is_active', true)->count(),
            'product_count' => (int) $productSummary->product_count,
            'supplier_count' => DB::table('suppliers')->where('business_id', $businessId)->where('is_active', true)->count(),
            'staff_count' => DB::table('users')->where('business_id', $businessId)->where('role', '<>', 'owner')->where('is_active', true)->count(),
            'stock_units' => (float) $productSummary->stock_units,
            'inventory_cost' => (int) round((float) $productSummary->inventory_cost),
            'low_stock_count' => (clone $products)->where('stock', '>', 0)->where('low_stock_threshold', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')->count(),
            'out_of_stock_count' => (clone $products)->where('stock', '<=', 0)->count(),
            'transaction_count' => (int) $saleSummary->sale_count + (int) $purchaseSummary->purchase_count + (int) $paymentSummary->payment_count + (int) $supplierPaymentSummary->payment_count,
            'sale_count' => (int) $saleSummary->sale_count,
            'sales_total' => (int) $saleSummary->sales_total,
            'purchase_count' => (int) $purchaseSummary->purchase_count,
            'purchase_total' => (int) $purchaseSummary->purchase_total,
            'supplier_payment_count' => (int) $supplierPaymentSummary->payment_count,
            'supplier_payable' => max(0, (int) DB::table('purchases')->where('business_id', $businessId)->where('status', 'completed')->sum('credit_amount') - (int) $supplierPaymentSummary->settled_total),
            'customer_payment_count' => (int) $paymentSummary->payment_count,
            'outstanding_credit' => max(0, (int) $saleSummary->credit_total - (int) $paymentSummary->net_collected),
            'expense_count' => (int) $expenseSummary->expense_count,
            'expense_total' => (int) $expenseSummary->expense_total,
            'last_activity_at' => $lastActivity?->utc()->toISOString(),
        ];
    }

    public function resetOwnerPassword(Request $request, int $businessId): array
    {
        abort_if(! DB::table('businesses')->where('id', $businessId)->exists(), 404, 'Business not found');
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $owner = DB::table('users')->where('business_id', $businessId)->where('role', 'owner')->first();
        abort_if(! $owner, 404, 'Business owner not found');

        DB::table('users')->where('id', $owner->id)->update([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email]];
    }

    public function requests(): array
    {
        $items = DB::table('subscription_requests as requests')
            ->join('businesses', 'businesses.id', '=', 'requests.business_id')
            ->join('subscription_plans as plans', 'plans.id', '=', 'requests.subscription_plan_id')
            ->select('requests.*', 'businesses.name as business_name', 'plans.name as plan_name', 'plans.price', 'plans.currency', 'plans.duration_days')
            ->orderByRaw("FIELD(requests.status, 'pending', 'approved', 'rejected')")->orderByDesc('requests.id')->get()
            ->map(function ($item) {
                $item->payment_screenshot_available = (bool) $item->payment_screenshot_path;
                $item->payment_screenshot_url = $item->payment_screenshot_path
                    ? "/api/office/subscription-requests/{$item->id}/payment-screenshot"
                    : null;
                unset($item->payment_screenshot_path);

                return $item;
            })->all();

        return ['items' => $items];
    }

    public function paymentScreenshot(int $requestId)
    {
        $subscriptionRequest = DB::table('subscription_requests')->where('id', $requestId)->first();
        abort_if(! $subscriptionRequest || ! $subscriptionRequest->payment_screenshot_path, 404, 'Payment screenshot not found');
        abort_unless(Storage::disk('local')->exists($subscriptionRequest->payment_screenshot_path), 404, 'Payment screenshot file not found');

        return Storage::disk('local')->response(
            $subscriptionRequest->payment_screenshot_path,
            'payment-proof-'.$requestId.'.'.pathinfo($subscriptionRequest->payment_screenshot_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function assign(Request $request, int $businessId): array
    {
        $data = $request->validate([
            'subscription_plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_system', false)],
            'record_financial' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'between:1,3650'],
            'price_paid' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);
        abort_if(! DB::table('businesses')->where('id', $businessId)->exists(), 404, 'Business not found');
        $this->activate($businessId, (int) $data['subscription_plan_id'], $data);

        return $this->subscriptions->status($businessId);
    }

    public function renew(Request $request, int $businessId): array
    {
        $data = $request->validate([
            'duration_days' => ['nullable', 'integer', 'between:1,3650'],
            'price_paid' => ['nullable', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($businessId, $data) {
            $hasSubscription = DB::table('business_subscriptions')->where('business_id', $businessId)->exists();
            abort_if(! $hasSubscription, 404, 'Business has no subscription to renew');

            $current = DB::table('business_subscriptions as subscriptions')
                ->join('subscription_plans as plans', 'plans.id', '=', 'subscriptions.subscription_plan_id')
                ->where('subscriptions.business_id', $businessId)
                ->where('subscriptions.access_type', 'paid')
                ->where('plans.is_system', false)
                ->select('subscriptions.*')
                ->orderByDesc('subscriptions.ends_at')->orderByDesc('subscriptions.id')->lockForUpdate()->first();
            abort_if(! $current, 403, 'Free trials cannot be renewed. Assign a paid plan instead.');
            $plan = DB::table('subscription_plans')->where('id', $current->subscription_plan_id)->first();
            abort_if(! $plan, 404, 'Plan not found');
            $now = now();
            $days = (int) ($data['duration_days'] ?? $plan->duration_days);
            $base = $current->ends_at && Carbon::parse($current->ends_at)->isFuture() ? Carbon::parse($current->ends_at) : $now;
            DB::table('business_subscriptions')->where('id', $current->id)->update([
                'status' => 'active', 'starts_at' => DB::raw('starts_at'),
                'ends_at' => $base->copy()->addDays($days), 'updated_at' => $now,
            ]);
            $this->recordPayment($businessId, (int) $plan->id, (int) $current->id, $plan, $days, $data, 'renewal', $now);
        });

        return $this->subscriptions->status($businessId);
    }

    public function extendTrial(Request $request, int $businessId): array
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'between:1,365'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $extension = DB::transaction(function () use ($businessId, $data) {
            abort_if(! DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(['id']), 404, 'Business not found');

            $entitlement = $this->subscriptions->status($businessId);
            abort_unless(
                $entitlement['access_type'] === 'trial' && $entitlement['subscription'],
                409,
                'Only a business currently on trial access can receive a trial extension.'
            );

            $trial = DB::table('business_subscriptions')
                ->where('id', (int) $entitlement['subscription']['id'])
                ->where('business_id', $businessId)
                ->where('access_type', 'trial')
                ->lockForUpdate()
                ->first();
            abort_if(! $trial, 409, 'The current trial record could not be extended.');

            $now = now();
            $days = (int) $data['days'];
            $previousEnd = $trial->ends_at ? Carbon::parse($trial->ends_at) : null;
            $base = $previousEnd && $previousEnd->isFuture() ? $previousEnd->copy() : $now->copy();
            $newEnd = $base->addDays($days);
            $admin = Auth::guard('office')->user();
            $audit = sprintf(
                'Trial extended %d day%s by %s on %s%s.',
                $days,
                $days === 1 ? '' : 's',
                $admin?->name ?: 'Office admin',
                $now->copy()->utc()->toDateTimeString().' UTC',
                trim((string) ($data['note'] ?? '')) !== '' ? ': '.trim((string) $data['note']) : ''
            );
            $note = trim(implode("\n", array_filter([trim((string) $trial->note), $audit])));

            DB::table('business_subscriptions')->where('id', $trial->id)->update([
                'status' => 'active',
                'ends_at' => $newEnd,
                'note' => $note,
                'updated_at' => $now,
            ]);

            return [
                'days_added' => $days,
                'previous_ends_at' => $previousEnd?->copy()->utc()->toISOString(),
                'ends_at' => $newEnd->copy()->utc()->toISOString(),
                'reactivated' => ! $previousEnd || $previousEnd->lessThanOrEqualTo($now),
            ];
        });

        return [
            'ok' => true,
            'extension' => $extension,
            'subscription' => $this->subscriptions->status($businessId),
        ];
    }

    public function cancel(int $businessId): array
    {
        DB::table('business_subscriptions')->where('business_id', $businessId)->where('status', 'active')
            ->update(['status' => 'cancelled', 'starts_at' => DB::raw('starts_at'), 'updated_at' => now()]);

        return $this->subscriptions->status($businessId);
    }

    public function setStatus(Request $request, int $businessId): array
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        DB::transaction(function () use ($businessId, $data) {
            abort_if(! DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(['id']), 404, 'Business not found');

            $entitlement = $this->subscriptions->status($businessId);
            $subscriptionId = (int) ($entitlement['subscription']['id'] ?? 0);
            abort_if($subscriptionId === 0, 404, 'Business has no subscription to update');

            $subscription = DB::table('business_subscriptions')
                ->where('id', $subscriptionId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();
            abort_if(! $subscription, 404, 'Subscription not found');

            if ($data['status'] === 'active') {
                abort_if(
                    $subscription->ends_at !== null && Carbon::parse($subscription->ends_at)->lessThanOrEqualTo(now()),
                    409,
                    'An expired subscription cannot be reactivated. Renew or assign a plan instead.'
                );
                abort_if(
                    Carbon::parse($subscription->starts_at)->isFuture(),
                    409,
                    'A scheduled subscription cannot be activated before its start date.'
                );
            }

            DB::table('business_subscriptions')->where('id', $subscriptionId)->update([
                'status' => $data['status'] === 'suspended' ? 'cancelled' : 'active',
                'starts_at' => DB::raw('starts_at'),
                'updated_at' => now(),
            ]);
        });

        return $this->subscriptions->status($businessId);
    }

    public function approve(Request $request, int $requestId): array
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string'], 'price_paid' => ['nullable', 'integer', 'min:0']]);
        $businessId = DB::transaction(function () use ($requestId, $data) {
            $subscriptionRequest = DB::table('subscription_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            abort_if(! $subscriptionRequest, 404, 'Pending request not found');
            $this->activate((int) $subscriptionRequest->business_id, (int) $subscriptionRequest->subscription_plan_id, $data);
            DB::table('subscription_requests')->where('id', $requestId)->update([
                'status' => 'approved', 'admin_note' => $data['admin_note'] ?? '',
                'reviewed_by_admin_id' => Auth::guard('office')->id(), 'reviewed_at' => now(), 'updated_at' => now(),
            ]);

            return (int) $subscriptionRequest->business_id;
        });

        return ['ok' => true, 'subscription' => $this->subscriptions->status($businessId)];
    }

    public function reject(Request $request, int $requestId): array
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string']]);
        abort_if(! DB::table('subscription_requests')->where('id', $requestId)->where('status', 'pending')->update([
            'status' => 'rejected', 'admin_note' => $data['admin_note'] ?? '',
            'reviewed_by_admin_id' => Auth::guard('office')->id(), 'reviewed_at' => now(), 'updated_at' => now(),
        ]), 404, 'Pending request not found');

        return ['ok' => true];
    }

    private function activate(int $businessId, int $planId, array $data): void
    {
        if (! ($data['record_financial'] ?? true)) {
            $data['price_paid'] = 0;
            $data['note'] = trim(($data['note'] ?? '').' Gift assignment: no financial record. Admin #'.Auth::guard('office')->id().' at '.now()->utc()->toISOString());
        }
        DB::transaction(function () use ($businessId, $planId, $data) {
            abort_if(! DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(['id']), 404, 'Business not found');
            $plan = DB::table('subscription_plans')->where('id', $planId)->first();
            abort_if(! $plan, 404, 'Plan not found');
            abort_if((bool) $plan->is_system, 403, 'System subscription plans cannot be assigned manually.');

            $now = now();
            $days = (int) ($data['duration_days'] ?? $plan->duration_days);
            $duplicate = ! isset($data['starts_at'])
                ? DB::table('business_subscriptions')
                    ->where('business_id', $businessId)
                    ->where('subscription_plan_id', $planId)
                    ->where('status', 'active')
                    ->where('starts_at', '<=', $now)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '>', $now)
                    ->orderByDesc('ends_at')
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($duplicate) {
                DB::table('business_subscriptions')->where('id', $duplicate->id)->update([
                    'starts_at' => DB::raw('starts_at'),
                    'ends_at' => Carbon::parse($duplicate->ends_at)->addDays($days),
                    'note' => ! ($data['record_financial'] ?? true)
                        ? trim(($duplicate->note ?? '')."\n".$data['note'].' Added '.$days.' days.')
                        : $duplicate->note,
                    'updated_at' => $now,
                ]);
                $this->recordPayment($businessId, $planId, (int) $duplicate->id, $plan, $days, $data, 'renewal', $now);

                return;
            }

            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $now;
            $activeTrial = ! isset($data['starts_at'])
                ? DB::table('business_subscriptions')
                    ->where('business_id', $businessId)
                    ->where('access_type', 'trial')
                    ->where('status', 'active')
                    ->where('starts_at', '<=', $now)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '>', $now)
                    ->orderByDesc('ends_at')
                    ->lockForUpdate()
                    ->first()
                : null;
            $termBase = $activeTrial ? Carbon::parse($activeTrial->ends_at) : $startsAt;
            DB::table('business_subscriptions')->where('business_id', $businessId)->where('status', 'active')
                ->update(['status' => 'cancelled', 'starts_at' => DB::raw('starts_at'), 'updated_at' => $now]);
            $subscriptionId = DB::table('business_subscriptions')->insertGetId([
                'business_id' => $businessId,
                'subscription_plan_id' => $planId,
                'status' => 'active',
                'access_type' => 'paid',
                'starts_at' => $startsAt,
                'ends_at' => $termBase->copy()->addDays($days),
                'price_paid' => $data['price_paid'] ?? $plan->price,
                'note' => $data['note'] ?? ($data['admin_note'] ?? ''),
                'created_by_admin_id' => Auth::guard('office')->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordPayment($businessId, $planId, $subscriptionId, $plan, $days, $data, 'assignment', $now);
        });
    }

    private function recordPayment(
        int $businessId,
        int $planId,
        int $subscriptionId,
        object $plan,
        int $days,
        array $data,
        string $type,
        Carbon $paidAt
    ): void {
        if (! ($data['record_financial'] ?? true)) return;
        DB::table('subscription_payments')->insert([
            'business_id' => $businessId,
            'subscription_plan_id' => $planId,
            'business_subscription_id' => $subscriptionId,
            'type' => $type,
            'amount' => $data['price_paid'] ?? $plan->price,
            'currency' => $plan->currency,
            'duration_days' => $days,
            'note' => $data['note'] ?? ($data['admin_note'] ?? ''),
            'created_by_admin_id' => Auth::guard('office')->id(),
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    }
}
