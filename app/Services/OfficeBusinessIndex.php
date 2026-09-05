<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class OfficeBusinessIndex
{
    public function query()
    {
        $now = now();
        $latestSubscription = DB::table('business_subscriptions as s')
            ->join('subscription_plans as p', 'p.id', '=', 's.subscription_plan_id')
            ->whereColumn('s.business_id', 'businesses.id')->select('s.id')
            ->orderByRaw("CASE WHEN s.status = 'active' AND s.starts_at <= ? AND (s.ends_at IS NULL OR s.ends_at > ?) THEN 0 ELSE 1 END", [$now, $now])
            ->orderByRaw("CASE WHEN s.access_type = 'paid' THEN 0 ELSE 1 END")
            ->orderByDesc('s.ends_at')->orderByDesc('s.id')->limit(1);
        $owner = DB::table('users')->whereColumn('users.business_id', 'businesses.id')->where('role', 'owner')->orderBy('id')->select('id')->limit(1);
        $base = DB::table('businesses')->select('businesses.*')->selectSub($latestSubscription, 'selected_subscription_id')->selectSub($owner, 'selected_owner_id');
        $sales = DB::table('sales')->where('status', 'completed')->where('created_at', '<=', $now)->groupBy('business_id')
            ->select('business_id')->selectRaw('MAX(created_at) as last_sale_at')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as sales_30d', [$now->copy()->subDays(30)])
            ->selectRaw('COUNT(DISTINCT CASE WHEN created_at >= ? THEN DATE(created_at) END) as selling_days_30d', [$now->copy()->subDays(30)]);
        $products = DB::table('products')->where('is_active', true)->groupBy('business_id')->select('business_id')->selectRaw('COUNT(*) as product_count');
        $pending = DB::table('subscription_requests')->where('status', 'pending')->groupBy('business_id')->select('business_id')->selectRaw('COUNT(*) as pending_requests');
        $facts = DB::query()->fromSub($base, 'b')
            ->leftJoin('users as owner', 'owner.id', '=', 'b.selected_owner_id')
            ->leftJoin('business_subscriptions as sub', 'sub.id', '=', 'b.selected_subscription_id')
            ->leftJoin('subscription_plans as plan', 'plan.id', '=', 'sub.subscription_plan_id')
            ->leftJoinSub($sales, 'activity', 'activity.business_id', '=', 'b.id')
            ->leftJoinSub($products, 'catalogue', 'catalogue.business_id', '=', 'b.id')
            ->leftJoinSub($pending, 'intent', 'intent.business_id', '=', 'b.id')
            ->select('b.id', 'b.name', 'b.created_at', 'owner.name as owner_name', 'owner.email as owner_email', 'activity.last_sale_at')
            ->selectRaw('COALESCE(activity.sales_30d, 0) as sales_30d, COALESCE(activity.selling_days_30d, 0) as selling_days_30d, COALESCE(catalogue.product_count, 0) as product_count, COALESCE(intent.pending_requests, 0) as pending_requests')
            ->selectRaw("CASE WHEN sub.id IS NULL THEN 'none' WHEN sub.status = 'cancelled' THEN 'cancelled' WHEN sub.status <> 'active' THEN 'expired' WHEN sub.starts_at > ? THEN 'scheduled' WHEN sub.ends_at IS NOT NULL AND sub.ends_at <= ? THEN 'expired' WHEN sub.access_type = 'trial' OR (sub.access_type IS NULL AND plan.slug = ?) THEN 'trial' ELSE 'paid' END as access_state", [$now, $now, config('mkpos.trial.plan_slug', 'free-trial')]);
        return DB::query()->fromSub($facts, 'facts')->select('facts.*')->selectRaw("CASE WHEN access_state = 'paid' THEN 'paid' WHEN pending_requests > 0 THEN 'requested' WHEN sales_30d >= 5 AND selling_days_30d >= 2 THEN 'engaged' WHEN product_count >= 5 THEN 'setup' ELSE 'early' END as prospect");
    }
}
