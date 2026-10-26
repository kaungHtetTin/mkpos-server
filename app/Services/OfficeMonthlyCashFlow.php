<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OfficeMonthlyCashFlow
{
    public function forYear(int $year, string $currency): array
    {
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $start->addYear();
        $subscriptions = DB::table('subscription_payments')->where('currency', $currency)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->selectRaw('SUBSTR(paid_at, 1, 7) as period, SUM(amount) as total')
            ->groupByRaw('SUBSTR(paid_at, 1, 7)')->pluck('total', 'period');
        $entries = DB::table('office_book_entries')->where('currency', $currency)->whereNull('voided_at')
            ->whereIn('type', ['income', 'expense'])
            ->where('entry_date', '>=', $start->toDateString())->where('entry_date', '<', $end->toDateString())
            ->selectRaw('SUBSTR(entry_date, 1, 7) as period, type, SUM(amount) as total')
            ->groupByRaw('SUBSTR(entry_date, 1, 7), type')->get()->groupBy('period');

        return array_map(function ($month) use ($start, $subscriptions, $entries) {
            $date = $start->month($month);
            $period = $date->format('Y-m');
            $manual = ($entries->get($period) ?? collect())->pluck('total', 'type');
            $income = round((float) ($subscriptions[$period] ?? 0) + (float) ($manual['income'] ?? 0), 2);
            $outcome = round((float) ($manual['expense'] ?? 0), 2);

            return ['month' => $month, 'label' => $date->format('M'), 'income' => $income,
                'outcome' => $outcome, 'profit' => round($income - $outcome, 2)];
        }, range(1, 12));
    }
}
