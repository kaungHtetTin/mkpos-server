<?php

namespace App\Services;

use Illuminate\Http\Request;

class ShopProductSearch
{
    public function apply($query, Request $request): void
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $search = trim($data['q'] ?? '');
        if ($search === '') return;

        // Preserve Unicode letters/marks (including Myanmar). Terms may occur
        // in different public fields and in any order; every term must match.
        $terms = preg_split('/[\s\p{Z}\x{200B}.\/\-]+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY);
        abort_if($terms === false, 422, 'Search text must contain valid characters.');
        $terms = array_values(array_unique($terms ?: [mb_strtolower($search)]));
        $fields = ['p.name', 'p.category', 'ep.description', 'p.sku', 'p.barcode'];

        foreach ($terms as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function ($match) use ($fields, $pattern) {
                foreach ($fields as $field) {
                    $match->orWhereRaw("LOWER(COALESCE($field, '')) LIKE ? ESCAPE '!'", [$pattern]);
                    // Joined words/codes also match spacing and common separators.
                    $compact = "LOWER(COALESCE($field, ''))";
                    foreach (["' '", 'CHAR(9)', 'CHAR(10)', 'CHAR(13)', "'-'", "'_'", "'.'", "'/'", 'CHAR(226,128,139 USING utf8mb4)', 'CHAR(194,160 USING utf8mb4)'] as $separator) {
                        $compact = "REPLACE($compact, $separator, '')";
                    }
                    $match->orWhereRaw("$compact LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
    }
}
