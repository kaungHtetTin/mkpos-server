<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OfficeBusinessDeletion;
use App\Services\OfficeBusinessIndex;
use App\Services\PasswordSecurity;
use App\Services\RealtimeActivity;
use App\Services\ShopChatRealtime;
use App\Services\SubscriptionApprovalEmail;
use App\Services\SubscriptionService;
use App\Services\SystemPaymentReview;
use App\Support\AppTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class BusinessSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

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
            if ($request->query($parameter, 'all') !== 'all') {
                $query->where($column, $request->query($parameter));
            }
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
            ->leftJoin('platform_admins as editors', 'editors.id', '=', 'subscriptions.last_changed_by_admin_id')
            ->where('subscriptions.business_id', $businessId)
            ->select(
                'subscriptions.*',
                'plans.name as plan_name',
                'plans.currency',
                'plans.duration_days',
                'admins.name as created_by_name', 'editors.name as last_changed_by_name'
            )->orderByDesc('subscriptions.starts_at')->orderByDesc('subscriptions.id')->get()
            ->map(function ($item) {
                $result = (array) $item;
                foreach (['starts_at', 'ends_at', 'created_at'] as $field) {
                    $result[$field] = $item->$field ? Carbon::parse($item->$field)->utc()->toISOString() : null;
                }
                $result['billing_status'] = $item->status === 'active' && $item->ends_at && Carbon::parse($item->ends_at)->isPast()
                    ? 'expired'
                    : $item->status;

                return $result;
            })->all();

        $location = DB::table('shop_owner_locations')->where('business_id', $businessId)->first();
        $proofs = DB::table('subscription_requests')->where('business_id', $businessId)->where('status', 'approved')
            ->whereNotNull('payment_screenshot_path')->get();
        $periodsPerPlan = collect($history)->countBy('subscription_plan_id');
        foreach ($history as &$period) {
            $period['payment_proofs'] = $proofs->filter(fn ($proof) => ($proof->business_subscription_id ?? null)
                ? (int) $proof->business_subscription_id === (int) $period['id']
                : (int) $proof->subscription_plan_id === (int) $period['subscription_plan_id'] && $periodsPerPlan[$period['subscription_plan_id']] === 1)
                ->map(fn ($proof) => ['id' => (int) $proof->id, 'url' => "/office/subscription-requests/{$proof->id}/payment-screenshot"])->values()->all();
        }
        unset($period);

        return [
            'business' => (array) $business,
            'billing' => $this->subscriptions->status($businessId),
            'business_summary' => $this->businessSummary($businessId),
            'shop_location' => $location ? [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'accuracy' => (float) $location->accuracy,
                'captured_at' => Carbon::parse($location->captured_at)->utc()->toISOString(),
            ] : null,
            'billing_history' => $history,
            'sales_ai_wallet' => Schema::hasTable('sales_ai_wallets') ? app(\App\Services\SalesAi\Wallet::class)->view($businessId) : null,
            'sales_ai_topups' => Schema::hasTable('sales_ai_topups') ? DB::table('sales_ai_topups as topups')
                ->leftJoin('platform_admins as reviewer', 'reviewer.id', '=', 'topups.reviewed_by_admin_id')
                ->where('topups.business_id', $businessId)->select('topups.*', 'reviewer.name as reviewed_by_name')->orderByDesc('topups.id')->get()
                ->map(fn ($row) => app(\App\Services\SalesAi\Topups::class)->present($row) + ['reviewed_by_name' => $row->reviewed_by_name])->all() : [],
            'storefront' => DB::table('storefront_settings')->where('business_id', $businessId)->first([
                'slug', 'display_name', 'storefront_enabled', 'marketplace_enabled', 'marketplace_suspended', 'marketplace_suspension_reason',
            ]),
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

        app(PasswordSecurity::class)->change(User::findOrFail($owner->id), $data['password']);

        return ['ok' => true, 'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email]];
    }

    public function requests(): array
    {
        $rows = DB::table('subscription_requests as requests')
            ->join('businesses', 'businesses.id', '=', 'requests.business_id')
            ->join('subscription_plans as plans', 'plans.id', '=', 'requests.subscription_plan_id')
            ->select('requests.*', 'businesses.name as business_name', 'plans.name as plan_name', 'plans.price', 'plans.currency', 'plans.duration_days')
            ->orderByRaw("FIELD(requests.status, 'pending', 'approved', 'rejected')")->orderByDesc('requests.id')->get();
        $reviews = Schema::hasTable('system_agent_reviews') ? DB::table('system_agent_reviews')->whereIn('payment_request_id', $rows->pluck('id'))->get()->keyBy('payment_request_id') : collect();
        $items = $rows->map(function ($item) use ($reviews) {
            $job = $reviews->get($item->id);
            $item->prevalidation = $job ? ['status' => $job->status, 'error' => $job->error,
                'report' => $job->report ? json_decode($job->report, true) : null] : null;
            foreach (['created_at', 'reviewed_at', 'updated_at'] as $field) {
                if (isset($item->$field)) {
                    $item->$field = AppTime::iso($item->$field);
                }
            }
            $item->payment_screenshot_available = (bool) $item->payment_screenshot_path;
            $item->payment_screenshot_url = $item->payment_screenshot_path
                ? "/api/office/subscription-requests/{$item->id}/payment-screenshot"
                : null;
            unset($item->payment_screenshot_path, $item->payment_transaction_key);

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

    public function updatePeriod(Request $request, int $businessId, int $subscriptionId): array
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['present', 'nullable', 'date', 'after:starts_at'],
            'original_starts_at' => ['required', 'date'],
            'original_ends_at' => ['present', 'nullable', 'date'],
        ]);
        DB::transaction(function () use ($businessId, $subscriptionId, $data) {
            abort_unless(DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(['id']), 404, 'Business not found.');
            $row = DB::table('business_subscriptions')->where('business_id', $businessId)->where('id', $subscriptionId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Subscription not found for this business.');
            $originalStart = Carbon::parse($data['original_starts_at']);
            $originalEnd = $data['original_ends_at'] ? Carbon::parse($data['original_ends_at']) : null;
            abort_if(! Carbon::parse($row->starts_at)->equalTo($originalStart)
                || ($row->ends_at === null) !== ($originalEnd === null)
                || ($row->ends_at && ! Carbon::parse($row->ends_at)->equalTo($originalEnd)), 409, 'This billing period changed. Cancel and reopen the editor after refreshing the business.');
            $start = Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone'));
            $end = $data['ends_at'] ? Carbon::parse($data['ends_at'])->setTimezone(config('app.timezone')) : null;
            abort_if($row->access_type === 'trial' && ! $end, 422, 'A free trial must have an end date.');
            if ($row->status === 'active') {
                $overlap = DB::table('business_subscriptions')->where('business_id', $businessId)->where('id', '<>', $subscriptionId)
                    ->where('status', 'active')->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $start));
                if ($end) {
                    $overlap->where('starts_at', '<', $end);
                }
                abort_if($overlap->exists(), 409, 'This period overlaps another active subscription.');
            }
            if ($start->equalTo(Carbon::parse($row->starts_at)) && (($end === null && $row->ends_at === null)
                || ($end && $row->ends_at && $end->equalTo(Carbon::parse($row->ends_at))))) {
                return;
            }
            $format = fn ($date) => $date ? Carbon::parse($date)->utc()->format('Y-m-d H:i:s').' UTC' : 'no expiry';
            $audit = 'Billing period changed by '.Auth::guard('office')->user()->name.' on '.now()->utc()->format('Y-m-d H:i:s').' UTC: '
                .$format($row->starts_at).' to '.$format($row->ends_at).' → '.$format($start).' to '.$format($end).'.';
            DB::table('business_subscriptions')->where('id', $subscriptionId)->update(['last_changed_by_admin_id' => Auth::guard('office')->id(),
                'starts_at' => $start, 'ends_at' => $end, 'note' => trim(($row->note ? $row->note."\n" : '').$audit), 'updated_at' => now(),
            ]);
            app(RealtimeActivity::class)->subscription($businessId, 'subscription.changed');
        });

        return $this->show($businessId);
    }

    public function assign(Request $request, int $businessId): array
    {
        $data = $request->validate([
            'subscription_plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_system', false)],
            'record_financial' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'between:1,3650'],
            'price_paid' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['nullable', 'integer', Rule::exists('office_bank_books', 'id')->where('is_active', true)],
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
            'account_id' => ['nullable', 'integer', Rule::exists('office_bank_books', 'id')->where('is_active', true)],
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
            DB::table('business_subscriptions')->where('id', $current->id)->update(['last_changed_by_admin_id' => Auth::guard('office')->id(),
                'status' => 'active', 'starts_at' => DB::raw('starts_at'),
                'ends_at' => $base->copy()->addDays($days), 'updated_at' => $now,
            ]);
            $this->recordPayment($businessId, (int) $plan->id, (int) $current->id, $plan, $days, $data, 'renewal', $now);
            app(RealtimeActivity::class)->subscription($businessId, 'subscription.changed');
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

            DB::table('business_subscriptions')->where('id', $trial->id)->update(['last_changed_by_admin_id' => Auth::guard('office')->id(),
                'status' => 'active',
                'ends_at' => $newEnd,
                'note' => $note,
                'updated_at' => $now,
            ]);

            app(RealtimeActivity::class)->subscription($businessId, 'subscription.changed');

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
        DB::transaction(function () use ($businessId) {
            $changed = DB::table('business_subscriptions')->where('business_id', $businessId)->where('status', 'active')
                ->update(['last_changed_by_admin_id' => Auth::guard('office')->id(), 'status' => 'cancelled', 'starts_at' => DB::raw('starts_at'), 'updated_at' => now()]);
            if ($changed) {
                app(RealtimeActivity::class)->subscription($businessId, 'subscription.cancelled');
            }
        });

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

            $nextStatus = $data['status'] === 'suspended' ? 'cancelled' : 'active';
            if ($subscription->status === $nextStatus) {
                return;
            }
            DB::table('business_subscriptions')->where('id', $subscriptionId)->update(['last_changed_by_admin_id' => Auth::guard('office')->id(),
                'status' => $data['status'] === 'suspended' ? 'cancelled' : 'active',
                'starts_at' => DB::raw('starts_at'),
                'updated_at' => now(),
            ]);
            app(RealtimeActivity::class)->subscription($businessId, $data['status'] === 'suspended' ? 'subscription.suspended' : 'subscription.restored');
        });

        return $this->subscriptions->status($businessId);
    }

    public function approve(Request $request, int $requestId): array
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string'], 'transaction_id' => ['sometimes', 'nullable', 'string', 'max:120'], 'price_paid' => ['nullable', 'integer', 'min:0'], 'account_id' => ['nullable', 'integer', Rule::exists('office_bank_books', 'id')->where('is_active', true)]]);
        $businessId = DB::transaction(function () use ($requestId, $data) {
            $subscriptionRequest = DB::table('subscription_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            abort_if(! $subscriptionRequest, 404, 'Pending request not found');
            $reference = array_key_exists('transaction_id', $data) ? trim($data['transaction_id'] ?? '') : null;
            $effectiveReference = $reference ?? ($subscriptionRequest->transaction_id ?? null);
            if (filled($effectiveReference)) {
                app(\App\Services\PaymentReceiptClaims::class)->assertAvailable($subscriptionRequest->payment_bank, $effectiveReference, 'subscription', (int) $subscriptionRequest->id);
                app(\App\Services\PaymentReceiptClaims::class)->claim(app(SystemPaymentReview::class)->transactionKey($subscriptionRequest->payment_bank, $effectiveReference), 'subscription', (int) $subscriptionRequest->id);
            }
            $this->activate((int) $subscriptionRequest->business_id, (int) $subscriptionRequest->subscription_plan_id, $data, false);
            DB::table('subscription_requests')->where('id', $requestId)->update([
                ...(Schema::hasColumn('subscription_requests', 'business_subscription_id') ? ['business_subscription_id' => DB::table('business_subscriptions')
                    ->where('business_id', $subscriptionRequest->business_id)->where('subscription_plan_id', $subscriptionRequest->subscription_plan_id)
                    ->where('status', 'active')->where('starts_at', '<=', now())->orderByDesc('ends_at')->value('id')] : []),
                ...($reference !== null ? ['transaction_id' => $reference ?: null, 'payment_transaction_key' => $reference !== ''
                    ? app(SystemPaymentReview::class)->transactionKey($subscriptionRequest->payment_bank, $reference) : null] : []),
                'status' => 'approved', 'admin_note' => $data['admin_note'] ?? '',
                'reviewed_by_admin_id' => Auth::guard('office')->id(), 'reviewed_at' => now(), 'updated_at' => now(),
            ]);

            $eventId = DB::table('subscription_approval_events')->insertGetId([
                'business_id' => $subscriptionRequest->business_id,
                'subscription_request_id' => $requestId,
                'plan_name' => DB::table('subscription_plans')->where('id', $subscriptionRequest->subscription_plan_id)->value('name'),
                'created_at' => now(),
            ]);
            DB::afterCommit(fn () => app(ShopChatRealtime::class)->publish(
                ['private-shop.subscription.business.'.$subscriptionRequest->business_id],
                'subscription.approved', ['event_id' => (int) $eventId]));
            app(RealtimeActivity::class)->subscription((int) $subscriptionRequest->business_id, 'subscription.approved', null, 'subscription-approval:'.$eventId);

            $business = DB::table('businesses')->where('id', $subscriptionRequest->business_id)->first();
            $plan = DB::table('subscription_plans')->where('id', $subscriptionRequest->subscription_plan_id)->first();
            $subscription = $this->subscriptions->status((int) $subscriptionRequest->business_id)['subscription'];
            $emailDetails = [
                'business_name' => $business->name, 'timezone' => $business->timezone ?: 'UTC',
                'plan_name' => $plan->name, 'currency' => $plan->currency,
                'amount_paid' => (int) ($data['price_paid'] ?? $plan->price),
                'starts_at' => $subscription['starts_at'], 'ends_at' => $subscription['ends_at'],
                'approved_at' => now()->toISOString(), 'reference' => 'REQ-'.$requestId,
            ];
            $adminId = (int) Auth::guard('office')->id();
            DB::afterCommit(fn () => app(SubscriptionApprovalEmail::class)->send(
                (int) $eventId, (int) $subscriptionRequest->business_id, $adminId, $emailDetails));

            return (int) $subscriptionRequest->business_id;
        });

        return ['ok' => true, 'subscription' => $this->subscriptions->status($businessId)];
    }

    public function reject(Request $request, int $requestId): array
    {
        $data = $request->validate(['admin_note' => ['required', 'string', 'min:5', 'max:2000']]);
        abort_if(mb_strlen(trim($data['admin_note'])) < 5, 422, 'Please provide a rejection reason for the business owner.');
        DB::transaction(function () use ($requestId, $data) {
            $row = DB::table('subscription_requests')->where('id', $requestId)->where('status', 'pending')->lockForUpdate()->first();
            abort_unless($row, 404, 'Pending request not found');
            DB::table('subscription_requests')->where('id', $requestId)->update([
                'status' => 'rejected', 'admin_note' => $data['admin_note'] ?? '',
                'reviewed_by_admin_id' => Auth::guard('office')->id(), 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            app(RealtimeActivity::class)->subscription((int) $row->business_id, 'subscription.rejected', $data['admin_note'] ?? null, 'subscription-rejection:'.$requestId);
        });

        return ['ok' => true];
    }

    private function activate(int $businessId, int $planId, array $data, bool $notify = true): void
    {
        if (! ($data['record_financial'] ?? true)) {
            $data['price_paid'] = 0;
            $data['note'] = trim(($data['note'] ?? '').' Gift assignment: no financial record. Admin #'.Auth::guard('office')->id().' at '.now()->utc()->toISOString());
        }
        DB::transaction(function () use ($businessId, $planId, $data, $notify) {
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
                DB::table('business_subscriptions')->where('id', $duplicate->id)->update(['last_changed_by_admin_id' => Auth::guard('office')->id(),
                    'starts_at' => DB::raw('starts_at'),
                    'ends_at' => Carbon::parse($duplicate->ends_at)->addDays($days),
                    'note' => ! ($data['record_financial'] ?? true)
                        ? trim(($duplicate->note ?? '')."\n".$data['note'].' Added '.$days.' days.')
                        : $duplicate->note,
                    'updated_at' => $now,
                ]);
                $this->recordPayment($businessId, $planId, (int) $duplicate->id, $plan, $days, $data, 'renewal', $now);
                if ($notify) {
                    app(RealtimeActivity::class)->subscription($businessId, 'subscription.changed');
                }

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
                ->update(['last_changed_by_admin_id' => Auth::guard('office')->id(), 'status' => 'cancelled', 'starts_at' => DB::raw('starts_at'), 'updated_at' => $now]);
            $subscriptionId = DB::table('business_subscriptions')->insertGetId([
                'last_changed_by_admin_id' => Auth::guard('office')->id(),
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
            if ($notify) {
                app(RealtimeActivity::class)->subscription($businessId, 'subscription.changed');
            }
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
        if (! ($data['record_financial'] ?? true)) {
            return;
        }
        $settings = DB::table('office_book_settings')->where('id', 1)->lockForUpdate()->first();
        $accountId = $data['account_id'] ?? $settings->subscription_account_id;
        abort_unless(DB::table('office_bank_books')->where('id', $accountId)->where('is_active', true)->exists(), 422, 'Choose an active subscription bank book.');
        DB::table('subscription_payments')->insert([
            'account_id' => $accountId,
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
