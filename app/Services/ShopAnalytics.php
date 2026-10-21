<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopAnalytics
{
    public function report(Request $request, int $businessId): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'currency' => ['nullable', 'string', 'max:20'],
        ]);
        $timezone = DB::table('businesses')->where('id', $businessId)->value('timezone') ?: 'Asia/Rangoon';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = isset($data['date_from']) ? CarbonImmutable::parse($data['date_from'], $timezone)->startOfDay() : $today->subDays(29);
        $to = isset($data['date_to']) ? CarbonImmutable::parse($data['date_to'], $timezone)->startOfDay() : $today;
        $days = (int) $from->diffInDays($to) + 1;
        if ($days < 1 || $days > 366) throw ValidationException::withMessages(['date_to' => 'Choose a range of 1 to 366 days.']);
        $currencies = DB::table('ecommerce_orders')->where('business_id', $businessId)->distinct()->orderBy('currency')->pluck('currency');
        $currency = $data['currency'] ?? (app(BusinessProfile::class)->read($businessId)['currency'] ?? 'Ks');
        if (! $currencies->contains($currency)) $currencies->push($currency);
        $orders = DB::table('ecommerce_orders')->where('business_id', $businessId)->where('currency', $currency);
        $range = fn ($query, $start, $end) => $query->where('created_at', '>=', \App\Support\AppTime::storage($start))->where('created_at', '<', \App\Support\AppTime::storage($end));
        $end = $to->addDay();
        $period = $range(clone $orders, $from, $end);
        $previousFrom = $from->subDays($days);
        $previous = $range(clone $orders, $previousFrom, $from);
        // Use the same account/normalized-phone identity as customer detail.
        $phone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
        $identity = "CASE WHEN platform_customer_id IS NOT NULL THEN CONCAT('account:', platform_customer_id) WHEN $phone <> '' THEN CONCAT('guest:', SHA2($phone, 256)) ELSE CONCAT('unknown:', id) END";
        $buyers = (clone $orders)->where('status', 'completed')->where('sale_voided', false)->where('created_at', '<', \App\Support\AppTime::storage($end))
            ->selectRaw("$identity as customer_key, MAX(customer_name) as name, COUNT(*) as purchases, SUM(total) as spend, MIN(created_at) as first_purchase, MAX(created_at) as last_purchase")
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as period_orders', [\App\Support\AppTime::storage($from)])->groupByRaw($identity);
        $previousBuyers = (clone $orders)->where('status', 'completed')->where('sale_voided', false)->where('created_at', '<', \App\Support\AppTime::storage($from))
            ->selectRaw("$identity as customer_key, COUNT(*) as purchases, MIN(created_at) as first_purchase")
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as period_orders', [\App\Support\AppTime::storage($previousFrom)])->groupByRaw($identity);
        $buyerSummary = fn ($query, $start) => DB::query()->fromSub($query, 'buyers')->where('period_orders', '>', 0)
            ->selectRaw('COUNT(*) as buyers, SUM(CASE WHEN first_purchase >= ? THEN 1 ELSE 0 END) as new_buyers, SUM(CASE WHEN first_purchase < ? THEN 1 ELSE 0 END) as returning_buyers, SUM(CASE WHEN purchases >= 2 THEN 1 ELSE 0 END) as repeat_buyers', [\App\Support\AppTime::storage($start), \App\Support\AppTime::storage($start)])->first();
        $summary = function ($query, $customerStats) {
            $row = (clone $query)->selectRaw("COUNT(*) as orders, SUM(CASE WHEN status = 'completed' AND sale_voided = 0 THEN 1 ELSE 0 END) as completed_orders, SUM(CASE WHEN status = 'completed' AND sale_voided = 0 THEN total ELSE 0 END) as sales, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END) as paid_order_value, SUM(CASE WHEN payment_status = 'refund_pending' THEN total ELSE 0 END) as refund_pending, SUM(CASE WHEN payment_status = 'refunded' THEN total ELSE 0 END) as refunded")->first();
            return ['sales' => (int) $row->sales, 'completed_orders' => (int) $row->completed_orders, 'orders' => (int) $row->orders,
                'average_order_value' => $row->completed_orders ? round($row->sales / $row->completed_orders, 2) : 0,
                'new_buyers' => (int) $customerStats->new_buyers, 'returning_buyers' => (int) $customerStats->returning_buyers,
                'repeat_purchase_rate' => $customerStats->buyers ? round(100 * $customerStats->repeat_buyers / $customerStats->buyers, 1) : 0,
                'cancellation_rate' => $row->orders ? round(100 * $row->cancelled / $row->orders, 1) : 0,
                'completion_rate' => $row->orders ? round(100 * $row->completed_orders / $row->orders, 1) : 0,
                'paid_order_value' => (int) $row->paid_order_value, 'refund_pending' => (int) $row->refund_pending, 'refunded' => (int) $row->refunded];
        };
        $currentStats = $buyerSummary(clone $buyers, $from);
        $previousStats = $buyerSummary($previousBuyers, $previousFrom);
        // Calendar boundaries come from PHP so business timezone and DST work without MySQL timezone tables.
        $cases = []; $bindings = []; $dates = [];
        for ($day = $from; $day->lessThan($end); $day = $day->addDay()) {
            $date = $day->toDateString(); $dates[] = $date;
            $cases[] = 'WHEN created_at >= ? AND created_at < ? THEN ?';
            array_push($bindings, \App\Support\AppTime::storage($day), \App\Support\AppTime::storage($day->addDay()), $date);
        }
        $bucket = 'CASE '.implode(' ', $cases).' END';
        $daily = (clone $period)->selectRaw("$bucket as date", $bindings)->selectRaw("SUM(CASE WHEN status = 'completed' AND sale_voided = 0 THEN total ELSE 0 END) as sales, SUM(CASE WHEN status = 'completed' AND sale_voided = 0 THEN 1 ELSE 0 END) as orders")->groupBy('date')->get()->keyBy('date');
        $topProducts = DB::table('ecommerce_order_items as item')->joinSub((clone $period)->where('status', 'completed')->where('sale_voided', false)->select('id'), 'orders', 'orders.id', '=', 'item.order_id')
            ->where('item.business_id', $businessId)->select('item.product_id', 'item.name', 'item.unit_name')
            ->selectRaw('SUM(item.quantity) as quantity, SUM(item.line_total - item.coupon_discount) as sales')->groupBy('item.product_id', 'item.name', 'item.unit_name')->orderByDesc('sales')->limit(10)->get();
        $outcomes = (clone $period)->select('status')->selectRaw('COUNT(*) as orders')->groupBy('status')->orderByDesc('orders')->get();
        $origins = (clone $period)->select('origin as name')->selectRaw('COUNT(*) as orders')->groupBy('origin')->orderByDesc('orders')->get();
        $fulfillment = (clone $period)->select('fulfillment as name')->selectRaw('COUNT(*) as orders')->groupBy('fulfillment')->orderByDesc('orders')->get();
        $prepaid = (clone $period)->where('payment_status', 'paid')->whereNotIn('status', ['completed', 'cancelled', 'expired'])->sum('total');
        $inactiveBefore = \App\Support\AppTime::storage($end->subDays(60));
        $segment = 'CASE WHEN purchases >= 2 AND last_purchase < ? THEN \'Inactive repeat buyers\' WHEN purchases >= 3 THEN \'Loyal buyers\' WHEN purchases = 2 THEN \'Repeat buyers\' ELSE \'One-time buyers\' END';
        $segmented = DB::query()->fromSub(clone $buyers, 'buyers')->select('spend')->selectRaw("$segment as segment", [$inactiveBefore]);
        $segments = DB::query()->fromSub($segmented, 'segmented')->select('segment as name')->selectRaw('COUNT(*) as buyers, SUM(spend) as spend')->groupBy('segment')->get();
        $followUp = DB::query()->fromSub(clone $buyers, 'buyers')->where('purchases', '>=', 2)->where('last_purchase', '<', $inactiveBefore)
            ->orderByDesc('spend')->limit(10)->get(['customer_key', 'name', 'purchases', 'spend', 'last_purchase']);
        return ['currency' => $currency, 'currencies' => $currencies->values(), 'timezone' => $timezone,
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'previous_from' => $previousFrom->toDateString(), 'previous_to' => $from->subDay()->toDateString(),
            'summary' => $summary($period, $currentStats), 'previous' => $summary($previous, $previousStats),
            'trend' => array_map(fn ($date) => ['date' => $date, 'sales' => (int) ($daily[$date]->sales ?? 0), 'orders' => (int) ($daily[$date]->orders ?? 0)], $dates),
            'top_products' => $topProducts, 'outcomes' => $outcomes, 'origins' => $origins, 'fulfillment' => $fulfillment, 'prepaid' => (int) $prepaid, 'segments' => $segments,
            'buyers' => [['name' => 'New buyers', 'buyers' => (int) $currentStats->new_buyers], ['name' => 'Returning buyers', 'buyers' => (int) $currentStats->returning_buyers]],
            'follow_up' => \App\Support\AppTime::shopPayload($followUp)];
    }
}
