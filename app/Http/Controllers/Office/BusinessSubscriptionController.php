<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
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
        $query = DB::table('businesses')->leftJoin('users', function ($join) {
            $join->on('users.business_id', '=', 'businesses.id')->where('users.role', 'owner');
        })->select('businesses.*', 'users.name as owner_name', 'users.email as owner_email');
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($filter) use ($search) {
                $like = "%{$search}%";
                $filter->where('businesses.name', 'like', $like)->orWhere('users.email', 'like', $like)->orWhere('users.name', 'like', $like);
            });
        }
        $items = $query->orderByDesc('businesses.id')->get()->map(function ($business) {
            $item = (array) $business;
            $item['subscription'] = $this->subscriptions->status((int) $business->id);

            return $item;
        })->all();

        return ['items' => $items, 'total' => count($items)];
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
            'billing_history' => $history,
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

    public function cancel(int $businessId): array
    {
        DB::table('business_subscriptions')->where('business_id', $businessId)->where('status', 'active')
            ->update(['status' => 'cancelled', 'starts_at' => DB::raw('starts_at'), 'updated_at' => now()]);

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
