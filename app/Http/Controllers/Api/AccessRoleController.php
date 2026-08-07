<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessRoleController extends Controller
{
    public function index(Request $request): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $query = AccessRole::where('business_id', $businessId)
            ->withCount(['staff' => fn ($query) => $query->where('role', 'staff')])
            ->orderBy('name');
        $total = (clone $query)->count();
        if ($request->has('limit')) {
            $query->offset(max(0, (int) $request->query('offset', 0)))
                ->limit(max(1, min((int) $request->query('limit'), 100)));
        }

        return [
            'modules' => collect(AccessService::ASSIGNABLE_MODULES)
                ->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values()->all(),
            'items' => $query->get()->all(),
        ] + ($request->boolean('with_total') ? ['total' => $total] : []);
    }

    public function show(Request $request, int $id): array
    {
        return AccessRole::where('business_id', (int) $request->user('web')->business_id)
            ->withCount(['staff' => fn ($query) => $query->where('role', 'staff')])->findOrFail($id)->toArray();
    }

    public function store(Request $request)
    {
        $businessId = (int) $request->user('web')->business_id;
        $data = $this->validated($request, $businessId);
        $role = AccessRole::create([
            'business_id' => $businessId,
            'name' => trim($data['name']),
            'permissions' => array_values(array_unique($data['permissions'])),
        ]);

        return response()->json($role, 201);
    }

    public function update(Request $request, int $id): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $role = AccessRole::where('business_id', $businessId)->findOrFail($id);
        $data = $this->validated($request, $businessId, $id);
        $role->update([
            'name' => trim($data['name']),
            'permissions' => array_values(array_unique($data['permissions'])),
        ]);

        return $role->fresh()->toArray();
    }

    public function destroy(Request $request, int $id): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $role = AccessRole::where('business_id', $businessId)->findOrFail($id);
        abort_if($role->staff()->where('role', 'staff')->exists(), 422, 'Reassign or delete this role\'s staff before deleting it.');
        $role->delete();

        return ['ok' => true];
    }

    private function validated(Request $request, int $businessId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('access_roles', 'name')->where('business_id', $businessId)->ignore($ignoreId),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in(array_keys(AccessService::ASSIGNABLE_MODULES))],
        ]);
    }
}
