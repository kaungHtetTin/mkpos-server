<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OfficeFinancialSnapshot
{
    private const TABLES = ['subscription_payments', 'business_subscriptions', 'office_book_entries', 'office_bank_books', 'office_book_settings', 'office_book_settings_audits', 'office_founders'];

    public function capture(?array $baseline = null): array
    {
        return DB::transaction(function () use ($baseline) {
            $tables = [];
            foreach ($baseline ? array_keys($baseline['tables']) : self::TABLES as $table) {
                if (!Schema::hasTable($table)) {
                    if ($baseline) throw new \RuntimeException('Missing financial table: '.$table);
                    continue;
                }
                // Compare the original columns after an additive migration.
                $columns = $baseline['tables'][$table]['columns'] ?? Schema::getColumnListing($table);
                sort($columns);
                $rows = DB::table($table)->select($columns)->orderBy('id')->get()->map(function ($row) {
                    return array_map(fn ($value) => $value === null ? null : (string) $value, (array) $row);
                })->all();
                // A NULL receipt book has always meant legacy book #2. Filling
                // that column is the one intended change to financial records.
                $comparable = $rows;
                if ($table === 'subscription_payments' && in_array('account_id', $columns, true)) {
                    foreach ($comparable as &$row) $row['account_id'] = $row['account_id'] ?? '2';
                    unset($row);
                }
                $tables[$table] = ['columns' => $columns, 'count' => count($rows), 'sha256' => hash('sha256', json_encode($comparable, JSON_THROW_ON_ERROR)), 'rows' => $rows];
            }
            return ['format_version' => 1, 'captured_at' => now()->utc()->toISOString(), 'tables' => $tables, 'statistics' => $this->statistics()];
        });
    }

    public function differences(array $before, array $after): array
    {
        $differences = [];
        foreach ($before['tables'] as $table => $expected) {
            if (($after['tables'][$table]['sha256'] ?? null) !== $expected['sha256']) $differences[] = $table.' original records changed';
        }
        if ($before['statistics'] !== $after['statistics']) $differences[] = 'Financial statistics changed';
        return $differences;
    }

    private function minor($amount): int
    {
        $parts = explode('.', ltrim((string) $amount, '-'));
        $minor = (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        return str_starts_with((string) $amount, '-') ? -$minor : $minor;
    }

    private function statistics(): array
    {
        $result = [];
        $bankIds = Schema::hasTable('office_bank_books') ? DB::table('office_bank_books')->pluck('id')->all() : [];
        $blank = fn () => ['receipts' => 0, 'subscription_income_minor' => 0, 'income_minor' => 0, 'expenses_minor' => 0, 'profit_minor' => 0, 'cash_minor' => 0, 'allocated_minor' => 0, 'unallocated_minor' => 0, 'unassigned_minor' => 0, 'bank_balances_minor' => array_fill_keys($bankIds, 0), 'founders' => [], 'monthly_subscription_income_minor' => []];
        foreach (DB::table('subscription_payments')->orderBy('id')->cursor() as $payment) {
            $result[$payment->currency] ??= $blank(); $stats = &$result[$payment->currency];
            $amount = $this->minor($payment->amount); $book = $payment->account_id ?? 2;
            $stats['receipts']++; $stats['subscription_income_minor'] += $amount; $stats['income_minor'] += $amount; $stats['cash_minor'] += $amount;
            $stats['bank_balances_minor'][$book] = ($stats['bank_balances_minor'][$book] ?? 0) + $amount;
            $month = Carbon::parse($payment->paid_at)->format('Y-m');
            $stats['monthly_subscription_income_minor'][$month] = ($stats['monthly_subscription_income_minor'][$month] ?? 0) + $amount;
            unset($stats);
        }
        if (Schema::hasTable('office_book_entries')) foreach (DB::table('office_book_entries')->whereNull('voided_at')->orderBy('id')->cursor() as $entry) {
            $result[$entry->currency] ??= $blank(); $stats = &$result[$entry->currency];
            $amount = $this->minor($entry->amount);
            $movement = in_array($entry->type, ['income', 'contribution', 'opening_balance'], true) ? $amount : -$amount;
            if ($entry->type === 'income') $stats['income_minor'] += $amount;
            if ($entry->type === 'expense') $stats['expenses_minor'] += $amount;
            if ($entry->type === 'profit_allocation') {
                $stats['allocated_minor'] += $amount;
                foreach (json_decode($entry->allocation, true, 512, JSON_THROW_ON_ERROR) as $share) {
                    $stats['founders'][$share['id']] ??= ['allocated_minor' => 0, 'paid_minor' => 0, 'contributed_minor' => 0];
                    $stats['founders'][$share['id']]['allocated_minor'] += $this->minor($share['amount']);
                    if ($entry->account_id) $stats['founders'][$share['id']]['paid_minor'] += $this->minor($share['amount']);
                }
                if (!$entry->account_id) { unset($stats); continue; }
            }
            if (in_array($entry->type, ['payout', 'contribution'], true)) {
                $stats['founders'][$entry->founder_id] ??= ['allocated_minor' => 0, 'paid_minor' => 0, 'contributed_minor' => 0];
                $stats['founders'][$entry->founder_id][$entry->type === 'payout' ? 'paid_minor' : 'contributed_minor'] += $amount;
            }
            if ($entry->account_id) $stats['bank_balances_minor'][$entry->account_id] = ($stats['bank_balances_minor'][$entry->account_id] ?? 0) + $movement;
            else $stats['unassigned_minor'] += $movement;
            if ($entry->type === 'transfer') $stats['bank_balances_minor'][$entry->to_account_id] = ($stats['bank_balances_minor'][$entry->to_account_id] ?? 0) + $amount;
            else $stats['cash_minor'] += $movement;
            unset($stats);
        }
        foreach ($result as &$stats) {
            $stats['profit_minor'] = $stats['income_minor'] - $stats['expenses_minor'];
            $stats['unallocated_minor'] = $stats['profit_minor'] - $stats['allocated_minor'];
            foreach ($stats['founders'] as &$founder) $founder['unpaid_minor'] = $founder['allocated_minor'] - $founder['paid_minor'];
            unset($founder);
            foreach (['bank_balances_minor', 'founders', 'monthly_subscription_income_minor'] as $key) ksort($stats[$key]);
        }
        unset($stats); ksort($result);
        return $result;
    }
}
