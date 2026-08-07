<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): array
    {
        return ['items' => DB::table('subscription_plans')->orderBy('sort_order')->orderBy('price')->get()
            ->map(fn ($plan) => $this->response($plan))->all()];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $id = DB::table('subscription_plans')->insertGetId($this->values($data) + ['created_at' => now(), 'updated_at' => now()]);

        return response()->json($this->find($id), 201);
    }

    public function update(Request $request, int $id): array
    {
        abort_if(! DB::table('subscription_plans')->where('id', $id)->exists(), 404, 'Plan not found');
        $data = $this->validated($request, $id);
        DB::table('subscription_plans')->where('id', $id)->update($this->values($data) + ['updated_at' => now()]);

        return $this->find($id);
    }

    public function destroy(int $id): array
    {
        abort_if(! DB::table('subscription_plans')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]), 404, 'Plan not found');

        return $this->find($id);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('subscription_plans', 'slug')->ignore($id)],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'max:20'],
            'duration_days' => ['required', 'integer', 'between:1,3650'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function values(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'slug' => trim($data['slug'] ?? '') ?: Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
            'description' => $data['description'] ?? '',
            'price' => $data['price'],
            'currency' => $data['currency'],
            'duration_days' => $data['duration_days'],
            'features' => json_encode(array_values($data['features'] ?? [])),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }

    private function find(int $id): array
    {
        $plan = DB::table('subscription_plans')->where('id', $id)->first();
        abort_if(! $plan, 404, 'Plan not found');

        return $this->response($plan);
    }

    private function response(object $plan): array
    {
        $item = (array) $plan;
        $item['features'] = $plan->features ? json_decode($plan->features, true) : [];
        $item['is_active'] = (bool) $plan->is_active;

        return $item;
    }
}
