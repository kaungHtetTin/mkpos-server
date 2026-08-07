<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    public function index(Request $request): array
    {
        $data = $request->validate([
            'currency' => ['nullable', 'string', 'max:20'],
        ]);
        $currency = $data['currency'] ?? DB::table('subscription_payments')
            ->select('currency', DB::raw('COUNT(*) as payment_count'))
            ->groupBy('currency')
            ->orderByDesc('payment_count')
            ->value('currency') ?? 'Ks';
        $now = now();
        $payments = DB::table('subscription_payments')->where('currency', $currency);

        $thisMonth = (clone $payments)
            ->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('amount');
        $thisYear = (clone $payments)
            ->whereBetween('paid_at', [$now->copy()->startOfYear(), $now->copy()->endOfYear()])
            ->sum('amount');
        $previousYear = (clone $payments)
            ->whereBetween('paid_at', [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()])
            ->sum('amount');
        $allTime = (clone $payments)->sum('amount');
        $allTimeSales = (clone $payments)->count();

        $monthlyRows = (clone $payments)
            ->whereYear('paid_at', $now->year)
            ->selectRaw('MONTH(paid_at) as month_number, SUM(amount) as amount, COUNT(*) as sales')
            ->groupByRaw('MONTH(paid_at)')
            ->get()->keyBy('month_number');
        $monthly = collect(range(1, 12))->map(function ($month) use ($monthlyRows) {
            $row = $monthlyRows->get($month);

            return [
                'month' => $month,
                'label' => now()->month($month)->format('M'),
                'amount' => (int) ($row->amount ?? 0),
                'sales' => (int) ($row->sales ?? 0),
            ];
        })->all();

        $yearly = (clone $payments)
            ->selectRaw('YEAR(paid_at) as year, SUM(amount) as amount, COUNT(*) as sales')
            ->groupByRaw('YEAR(paid_at)')
            ->orderBy('year')
            ->get()->map(fn ($row) => [
                'year' => (int) $row->year,
                'amount' => (int) $row->amount,
                'sales' => (int) $row->sales,
            ])->all();

        $recentSales = DB::table('subscription_payments as payments')
            ->join('businesses', 'businesses.id', '=', 'payments.business_id')
            ->join('subscription_plans as plans', 'plans.id', '=', 'payments.subscription_plan_id')
            ->leftJoin('platform_admins as admins', 'admins.id', '=', 'payments.created_by_admin_id')
            ->where('payments.currency', $currency)
            ->select(
                'payments.id',
                'payments.type',
                'payments.amount',
                'payments.currency',
                'payments.duration_days',
                'payments.paid_at',
                'businesses.name as business_name',
                'plans.name as plan_name',
                'admins.name as created_by_name'
            )->orderByDesc('payments.paid_at')->orderByDesc('payments.id')->limit(8)->get()->all();

        return [
            'currency' => $currency,
            'currencies' => DB::table('subscription_payments')->distinct()->orderBy('currency')->pluck('currency')->all(),
            'current_year' => $now->year,
            'summary' => [
                'this_month' => (int) $thisMonth,
                'this_year' => (int) $thisYear,
                'previous_year' => (int) $previousYear,
                'all_time' => (int) $allTime,
                'all_time_sales' => $allTimeSales,
                'year_over_year_percent' => $previousYear > 0
                    ? round((($thisYear - $previousYear) / $previousYear) * 100, 1)
                    : null,
            ],
            'monthly' => $monthly,
            'yearly' => $yearly,
            'recent_sales' => $recentSales,
        ];
    }
}
