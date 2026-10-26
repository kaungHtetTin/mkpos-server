<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Current account balances shared by business reports and owner AI reads. */
class BusinessAccounts
{
    public function query(bool $supplier): Builder
    {
        $business = app(TenantContext::class)->requireId();
        $parties = $supplier ? 'suppliers' : 'customers';
        $transactions = $supplier ? 'purchases' : 'sales';
        $payments = $supplier ? 'supplier_payments' : 'customer_payments';
        $key = $supplier ? 'supplier_id' : 'customer_id';
        $direction = $supplier ? 'shop_to_supplier' : 'customer_to_shop';
        $credit = DB::table($transactions)->where($transactions.'.business_id', $business)->whereColumn($key, $parties.'.id')
            ->where('status', 'completed')->selectRaw('COALESCE(SUM(credit_amount),0)');
        $paid = DB::table($payments)->where($payments.'.business_id', $business)->whereColumn($key, $parties.'.id')
            ->where('status', 'completed')->selectRaw('COALESCE(SUM(CASE WHEN direction=? THEN amount ELSE -amount END),0)', [$direction]);

        return DB::table($parties)->where($parties.'.business_id', $business)->where('is_active', true)
            ->select(['id', 'name', 'phone'])
            ->selectRaw('('.$credit->toSql().') - ('.$paid->toSql().') as balance', [...$credit->getBindings(), ...$paid->getBindings()]);
    }

    public function totals(bool $supplier): array
    {
        return (array) DB::query()->fromSub($this->query($supplier), 'accounts')->selectRaw(
            'COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END),0) as positive_total,
            COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END),0) as negative_total,
            COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END),0) as owing_count,
            COALESCE(SUM(CASE WHEN balance <> 0 THEN 1 ELSE 0 END),0) as open_count'
        )->first();
    }
}
