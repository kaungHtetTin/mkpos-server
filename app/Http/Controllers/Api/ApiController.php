<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class ApiController extends Controller
{
    protected function page(Builder $query, Request $request, int $defaultLimit = 25, int $maxLimit = 500, bool $optionalLimit = false)
    {
        $withTotal = $request->boolean('with_total');
        $offset = max((int) $request->query('offset', 0), 0);
        $hasLimit = $request->query->has('limit');
        $limit = $hasLimit ? max(1, min((int) $request->query('limit'), $maxLimit)) : $defaultLimit;
        $total = (clone $query)->count();
        if (! $optionalLimit || $hasLimit) {
            $query->limit($limit)->offset($offset);
        }
        $items = $query->get()->map(fn ($row) => (array) $row)->all();

        return $withTotal
            ? ['items' => $items, 'total' => $total, 'limit' => (! $optionalLimit || $hasLimit) ? $limit : $total, 'offset' => $offset]
            : $items;
    }

    protected function setting(string $key, string $default = ''): string
    {
        return (string) (DB::table('settings')->where('key', $key)->value('value') ?? $default);
    }

    protected function requireAdminPin(?string $pin): void
    {
        $hash = $this->setting('admin_pin_hash');
        if ($hash !== '' && ! password_verify((string) $pin, $hash)) {
            abort(403, 'Admin PIN is incorrect');
        }
    }

    protected function insertMovement(int $productId, string $productName, string $type, float $quantity, string $referenceType = '', ?int $referenceId = null, string $note = ''): void
    {
        DB::table('stock_movements')->insert([
            'product_id' => $productId, 'product_name' => $productName, 'movement_type' => $type,
            'quantity_change' => $quantity, 'reference_type' => $referenceType,
            'reference_id' => $referenceId, 'note' => $note, 'created_at' => now(),
        ]);
    }
}
