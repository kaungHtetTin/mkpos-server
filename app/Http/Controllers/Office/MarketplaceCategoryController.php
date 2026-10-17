<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketplaceCategoryController extends Controller
{
    private function tree() { return app(\App\Services\MarketplaceTaxonomy::class)->tree(); }

    private function present(object $row, $tree): array
    {
        return app(\App\Services\MarketplaceTaxonomy::class)->present($row, $tree);
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'in:all,active,inactive'],
            'level' => ['nullable', 'in:all,root,child'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])]]);
        $query = DB::table('marketplace_categories as c')->select('c.*')
            ->selectSub(DB::table('marketplace_product_categories as link')->selectRaw('COUNT(*)')->whereColumn('link.category_id', 'c.id'), 'products_count')
            ->selectSub(DB::table('marketplace_categories as child')->selectRaw('COUNT(*)')->whereColumn('child.parent_id', 'c.id'), 'children_count');
        if ($q = trim($data['q'] ?? '')) $query->where(fn ($builder) => $builder->where('c.name_en', 'like', '%'.$q.'%')->orWhere('c.name_my', 'like', '%'.$q.'%')->orWhere('c.slug', 'like', '%'.$q.'%'));
        if (in_array($data['status'] ?? 'all', ['active', 'inactive'])) $query->where('c.is_active', $data['status'] === 'active');
        if (($data['level'] ?? 'all') === 'root') $query->whereNull('c.parent_id');
        if (($data['level'] ?? 'all') === 'child') $query->whereNotNull('c.parent_id');
        $total = (clone $query)->count(); $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $lastPage);
        $tree = $this->tree();
        return ['items' => $query->orderBy('c.sort_order')->orderBy('c.name_en')->orderBy('c.id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(fn ($row) => $this->present($row, $tree)),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
    }

    public function options(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1'], 'exclude_id' => ['nullable', 'integer', 'min:1']]);
        $tree = $this->tree(); $excluded = [];
        if (!empty($data['exclude_id'])) {
            $children = [];
            foreach ($tree as $row) $children[$row->parent_id ?? 0][] = (int) $row->id;
            $queue = [(int) $data['exclude_id']];
            for ($i = 0; $i < count($queue); $i++) {
                $id = $queue[$i]; if (isset($excluded[$id])) continue;
                $excluded[$id] = true;
                array_push($queue, ...($children[$id] ?? []));
            }
        }
        $q = mb_strtolower(trim($data['q'] ?? ''));
        $items = $tree->reject(fn ($row) => isset($excluded[$row->id]))
            ->map(fn ($row) => $this->present($row, $tree))
            ->filter(fn ($row) => $q === '' || str_contains(mb_strtolower($row['path'].' '.($row['name_my'] ?? '').' '.$row['slug']), $q))
            ->sortBy('path')->values();
        $page = (int) ($data['page'] ?? 1); $perPage = 50;
        return ['items' => $items->slice(($page - 1) * $perPage, $perPage)->values(), 'has_more' => $items->count() > $page * $perPage];
    }

    private function row(int $id): object
    {
        $row = DB::table('marketplace_categories')->where('id', $id)->first();
        abort_unless($row, 404, 'Category not found.');
        return $row;
    }

    public function show(int $id): array
    {
        return $this->present($this->row($id), $this->tree());
    }

    private function validated(Request $request, ?int $id): array
    {
        $data = $request->validate(['name_en' => ['required', 'string', 'max:160'], 'name_my' => ['nullable', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('marketplace_categories', 'slug')->ignore($id)],
            'parent_id' => ['nullable', 'integer', Rule::exists('marketplace_categories', 'id')],
            'is_active' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'version' => [$id ? 'required' : 'sometimes', 'integer', 'min:1']]);
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $visited = []; $parent = $data['parent_id'];
        while ($parent) {
            if ($parent === $id || isset($visited[$parent])) throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent or be moved below one of its children.']);
            $visited[$parent] = true;
            $parent = (int) $this->row($parent)->parent_id;
        }
        return $data;
    }

    private function audit(Request $request, int $id, string $action, ?object $before, ?object $after): void
    {
        DB::table('marketplace_category_audits')->insert(['category_id' => $id, 'admin_id' => $request->user('office')->id,
            'action' => $action, 'before' => $before ? json_encode($before) : null, 'after' => $after ? json_encode($after) : null, 'created_at' => now()]);
    }

    private function save(Request $request, ?int $id): array
    {
        return DB::transaction(function () use ($request, $id) {
            DB::table('marketplace_category_locks')->where('id', 1)->lockForUpdate()->first();
            $before = $id ? $this->row($id) : null;
            $data = $this->validated($request, $id);
            if ($before) abort_unless((int) $before->version === (int) $data['version'], 409, 'This category changed. Close and reopen the editor to load the latest version.');
            unset($data['version']);
            $data['updated_by_admin_id'] = $request->user('office')->id; $data['updated_at'] = now();
            if ($before) {
                $data['version'] = (int) $before->version + 1;
                DB::table('marketplace_categories')->where('id', $id)->update($data);
            } else {
                $id = DB::table('marketplace_categories')->insertGetId($data + ['created_by_admin_id' => $request->user('office')->id, 'created_at' => now()]);
            }
            $after = $this->row($id);
            $this->audit($request, $id, $before ? 'updated' : 'created', $before, $after);
            return $this->present($after, $this->tree());
        });
    }

    public function store(Request $request)
    {
        return response()->json($this->save($request, null), 201);
    }

    public function update(Request $request, int $id): array { return $this->save($request, $id); }

    public function destroy(Request $request, int $id): array
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        return DB::transaction(function () use ($request, $id, $data) {
            DB::table('marketplace_category_locks')->where('id', 1)->lockForUpdate()->first();
            $before = $this->row($id);
            abort_unless((int) $before->version === (int) $data['version'], 409, 'This category changed. Refresh the list before deleting it.');
            abort_if(DB::table('marketplace_categories')->where('parent_id', $id)->exists(), 409, 'Move or delete child categories before deleting this category.');
            abort_if(DB::table('marketplace_product_categories')->where('category_id', $id)->exists(), 409, 'Unlink or move the products assigned to this category before deleting it.');
            DB::table('marketplace_categories')->where('id', $id)->delete();
            $this->audit($request, $id, 'deleted', $before, null);
            return ['ok' => true];
        });
    }
}
