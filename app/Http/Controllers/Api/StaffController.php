<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    public function index(Request $request): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $query = User::with('accessRole:id,name,permissions')
            ->where('business_id', $businessId)->where('role', 'staff')->orderBy('name');
        $total = (clone $query)->count();
        if ($request->has('limit')) {
            $query->offset(max(0, (int) $request->query('offset', 0)))
                ->limit(max(1, min((int) $request->query('limit'), 100)));
        }

        return ['items' => $query->get()->map(fn (User $staff) => $this->item($staff))->all()]
            + ($request->boolean('with_total') ? ['total' => $total] : []);
    }

    public function show(Request $request, int $id): array
    {
        return $this->item($this->staff((int) $request->user('web')->business_id, $id)->load('accessRole'));
    }

    public function store(Request $request)
    {
        $businessId = (int) $request->user('web')->business_id;
        $data = $this->validated($request, $businessId);
        $staff = User::create([
            'business_id' => $businessId,
            'access_role_id' => $data['access_role_id'],
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role' => 'staff',
            'is_active' => $data['is_active'],
        ]);

        return response()->json($this->item($staff->load('accessRole')), 201);
    }

    public function update(Request $request, int $id): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $staff = $this->staff($businessId, $id);
        $data = $this->validated($request, $businessId, $staff);
        $staff->fill([
            'access_role_id' => $data['access_role_id'],
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'is_active' => $data['is_active'],
        ])->save();

        return $this->item($staff->fresh()->load('accessRole'));
    }

    public function resetPassword(Request $request, int $id): array
    {
        $businessId = (int) $request->user('web')->business_id;
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        $staff = $this->staff($businessId, $id);
        $staff->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ])->save();

        return ['ok' => true];
    }

    public function destroy(Request $request, int $id): array
    {
        $this->staff((int) $request->user('web')->business_id, $id)->delete();

        return ['ok' => true];
    }

    private function staff(int $businessId, int $id): User
    {
        return User::where('business_id', $businessId)->where('role', 'staff')->findOrFail($id);
    }

    private function validated(Request $request, int $businessId, ?User $staff = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff?->id)],
            'access_role_id' => ['required', Rule::exists('access_roles', 'id')->where('business_id', $businessId)],
            'is_active' => ['required', 'boolean'],
            'password' => [$staff ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ]);
    }

    private function item(User $staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'is_active' => (bool) $staff->is_active,
            'access_role_id' => $staff->access_role_id,
            'access_role' => $staff->accessRole ? [
                'id' => $staff->accessRole->id,
                'name' => $staff->accessRole->name,
                'permissions' => $staff->accessRole->permissions,
            ] : null,
            'created_at' => $staff->created_at,
        ];
    }
}
