<?php

namespace App\Services\SalesAi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WalletUsage
{
    public function report(int $businessId, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        return ['timezone' => $timezone, 'currency' => 'MMK',
            'daily' => $this->series($businessId, $now->startOfDay()->subDays(29), 30, false, $now),
            'monthly' => $this->series($businessId, $now->startOfMonth()->subMonths(11), 12, true, $now)];
    }

    private function series(int $businessId, CarbonImmutable $start, int $count, bool $monthly, CarbonImmutable $now): array
    {
        $select = []; $bindings = []; $items = [];
        for ($i = 0; $i < $count; $i++) {
            $from = $monthly ? $start->addMonths($i) : $start->addDays($i);
            $to = $monthly ? $from->addMonth() : $from->addDay();
            $select[] = 'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN -amount_micros ELSE 0 END), 0) AS bucket_'.$i;
            $bindings[] = $from->utc()->format('Y-m-d H:i:s');
            $bindings[] = $to->utc()->format('Y-m-d H:i:s');
            $items[] = ['key' => $from->format($monthly ? 'Y-m' : 'Y-m-d'), 'label' => $from->format($monthly ? 'M Y' : 'M j')];
        }
        $totals = DB::table('sales_ai_entries')->where('business_id', $businessId)->where('type', 'usage')->where('amount_micros', '<', 0)
            ->where('created_at', '>=', $start->utc()->format('Y-m-d H:i:s'))->where('created_at', '<=', $now->utc()->format('Y-m-d H:i:s'))
            ->selectRaw(implode(', ', $select), $bindings)->first();
        $total = 0;
        foreach ($items as $i => &$item) { $amount = (int) $totals->{'bucket_'.$i}; $item['cost'] = Pricing::amount($amount); $total += $amount; }
        return ['items' => $items, 'total' => Pricing::amount($total)];
    }
}
