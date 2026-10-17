<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MarketplaceTaxonomy
{
    public function tree()
    {
        return DB::table('marketplace_categories')->get(['id', 'parent_id', 'name_en', 'name_my', 'slug', 'is_active'])->keyBy('id');
    }

    public function present(object $row, $tree): array
    {
        $names = []; $myNames = []; $visited = []; $current = $row; $active = (bool) $row->is_active;
        while ($current && !isset($visited[$current->id])) {
            $visited[$current->id] = true;
            array_unshift($names, $current->name_en);
            array_unshift($myNames, $current->name_my ?: $current->name_en);
            $active = $active && (bool) $current->is_active;
            $current = $current->parent_id ? $tree->get($current->parent_id) : null;
        }
        $result = (array) $row;
        $result['is_active'] = (bool) $row->is_active;
        $result['parent_id'] = $row->parent_id ? (int) $row->parent_id : null;
        return $result + ['path' => implode(' / ', $names), 'path_my' => implode(' / ', $myNames), 'depth' => count($names),
            'effective_active' => $active, 'parent_name_en' => $row->parent_id ? $tree->get($row->parent_id)?->name_en : null];
    }

    public function categories()
    {
        $tree = $this->tree();
        return $tree->map(fn ($row) => $this->present($row, $tree));
    }

    public function descendants(int $id, $categories): array
    {
        $children = [];
        foreach ($categories as $category) $children[$category['parent_id'] ?? 0][] = (int) $category['id'];
        $queue = [$id]; $visited = [];
        for ($i = 0; $i < count($queue); $i++) {
            $current = $queue[$i]; if (isset($visited[$current])) continue;
            $visited[$current] = true; array_push($queue, ...($children[$current] ?? []));
        }
        return array_keys($visited);
    }
}
