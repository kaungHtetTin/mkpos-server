<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function plans(): array
    {
        return ['items' => DB::table('subscription_plans')->where('is_active', true)
            ->orderBy('sort_order')->orderBy('price')->get()->map(function ($plan) {
                $item = (array) $plan;
                $item['features'] = $plan->features ? json_decode($plan->features, true) : [];

                return $item;
            })->all()];
    }

    public function status(Request $request): array
    {
        return $this->subscriptions->status((int) $request->user('web')->business_id);
    }

    public function paymentMethods(): array
    {
        return [
            'items' => DB::table('payment_methods')
                ->where('is_active', true)
                ->select('id', 'bank', 'account_name', 'account_no')
                ->orderBy('sort_order')
                ->orderBy('bank')
                ->orderBy('id')
                ->get()
                ->all(),
        ];
    }

    public function billingHistory(Request $request): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $query = DB::table('subscription_payments as payments')
                ->join('subscription_plans as plans', 'plans.id', '=', 'payments.subscription_plan_id')
                ->where('payments.business_id', $businessId)
                ->select(
                    'payments.id',
                    'payments.type',
                    'payments.amount',
                    'payments.currency',
                    'payments.duration_days',
                    'payments.note',
                    'payments.paid_at',
                    'plans.name as plan_name',
                    'plans.slug as plan_slug'
                );
        $total = (clone $query)->count();
        $limit = $request->has('limit') ? max(1, min((int) $request->query('limit'), 100)) : 100;
        $offset = max(0, (int) $request->query('offset', 0));
        $items = $query->orderByDesc('payments.paid_at')->orderByDesc('payments.id')->offset($offset)->limit($limit)->get()->all();

        return ['items' => $items] + ($request->boolean('with_total') ? ['total' => $total, 'limit' => $limit, 'offset' => $offset] : []);
    }

    public function requestPlan(Request $request)
    {
        $data = $request->validate([
            'subscription_plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'payment_screenshot' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $businessId = (int) $request->user('web')->business_id;
        $paymentMethod = DB::table('payment_methods')->where('id', $data['payment_method_id'])->first();
        abort_if(! $paymentMethod || ! $paymentMethod->is_active, 422, 'The selected payment method is unavailable');
        $screenshotPath = $request->file('payment_screenshot')->store('subscription-payment-screenshots', 'local');

        try {
            $id = DB::transaction(function () use ($businessId, $data, $paymentMethod, $screenshotPath) {
                DB::table('subscription_requests')->where('business_id', $businessId)->where('status', 'pending')
                    ->update(['status' => 'rejected', 'admin_note' => 'Replaced by a newer request', 'reviewed_at' => now(), 'updated_at' => now()]);

                return DB::table('subscription_requests')->insertGetId([
                    'business_id' => $businessId,
                    'subscription_plan_id' => $data['subscription_plan_id'],
                    'payment_method_id' => $paymentMethod->id,
                    'payment_bank' => $paymentMethod->bank,
                    'payment_account_name' => $paymentMethod->account_name,
                    'payment_account_no' => $paymentMethod->account_no,
                    'status' => 'pending',
                    'message' => $data['message'] ?? '',
                    'payment_screenshot_path' => $screenshotPath,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($screenshotPath);
            throw $error;
        }

        return response()->json(['request_id' => $id, 'subscription' => $this->subscriptions->status($businessId)], 201);
    }
}
