<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OwnerPaymentValidation;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function plans(): array
    {
        return ['items' => DB::table('subscription_plans')
            ->where('is_active', true)
            ->where('is_public', true)
            ->where('is_system', false)
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
            'subscription_plan_id' => ['required', Rule::exists('subscription_plans', 'id')
                ->where('is_active', true)
                ->where('is_public', true)
                ->where('is_system', 0)],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'payment_screenshot' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $businessId = (int) $request->user('web')->business_id;
        $paymentMethod = DB::table('payment_methods')->where('id', $data['payment_method_id'])->first();
        $ownerId = (int) $request->user('web')->id;
        $plan = DB::table('subscription_plans')->where('id', $data['subscription_plan_id'])->first();
        abort_unless($plan && $plan->is_active && $plan->is_public && ! $plan->is_system, 422, 'The selected plan is unavailable.');
        abort_if(! $paymentMethod || ! $paymentMethod->is_active, 422, 'The selected payment method is unavailable');
        $screenshotPath = $request->file('payment_screenshot')->store('subscription-payment-screenshots', 'local');

        try {
            $validator = app(OwnerPaymentValidation::class);
            $validation = $validator->check($ownerId, $businessId, $plan, $paymentMethod, $screenshotPath);
            $id = $validator->submit($validation, $ownerId, $businessId, (int) $plan->id, (int) $paymentMethod->id, $screenshotPath, $data['message'] ?? '');
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($screenshotPath);
            throw $error;
        }

        if (DB::table('subscription_requests')->where('id', $id)->value('payment_screenshot_path') !== $screenshotPath) {
            Storage::disk('local')->delete($screenshotPath);
        }

        return response()->json(['request_id' => $id, 'subscription' => $this->subscriptions->status($businessId)], 201);
    }
}
