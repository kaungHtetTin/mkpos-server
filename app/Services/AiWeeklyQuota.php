<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AiWeeklyQuota
{
    public function usage(int $userId, int $limit): array
    {
        $now = now(AiUsageReport::TIMEZONE);
        $start = $now->copy()->startOfWeek(CarbonInterface::MONDAY);
        $used = DB::table('ai_runs')->where('user_id', $userId)
            ->where('created_at', '>=', $start->copy()->utc())
            ->where('created_at', '<=', $now->copy()->utc())->count();
        $remaining = max(0, $limit - $used);

        return ['limit' => $limit, 'used' => $used, 'remaining' => $remaining,
            'remaining_percent' => $limit > 0 ? round($remaining / $limit * 100, 1) : 0,
            'resets_at' => $start->addWeek()->toIso8601String(), 'timezone' => AiUsageReport::TIMEZONE];
    }
}
