<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminManagementController extends Controller
{
    public function index(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 25, 50, 100])]]);
        $query = PlatformAdmin::query()->select('id', 'name', 'email', 'role', 'is_active', 'created_at');
        if ($q = trim($data['q'] ?? '')) $query->where(fn ($qf) => $qf->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
        if (($data['status'] ?? 'all') !== 'all') $query->where('is_active', $data['status'] === 'active');
        return $this->paginate($query->orderBy('id'), $data);
    }

    public function store(Request $request): array
    {
        return $this->save($request, new PlatformAdmin);
    }

    public function update(Request $request, int $admin): array
    {
        return $this->save($request, PlatformAdmin::findOrFail($admin));
    }

    private function save(Request $request, PlatformAdmin $admin): array
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('platform_admins')->ignore($admin->id)],
            'role' => ['required', Rule::in(['super_admin', 'admin'])], 'is_active' => ['required', 'boolean'],
            'password' => [$admin->exists ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);
        DB::transaction(function () use ($admin, $data) {
            // Serialize changes so concurrent edits cannot remove every owner.
            PlatformAdmin::orderBy('id')->lockForUpdate()->get(['id']);
            if ($admin->exists) {
                $admin->refresh();
                abort_if($admin->id === Auth::guard('office')->id() && (!$data['is_active'] || $data['role'] !== 'super_admin'), 422, 'You cannot deactivate or demote your own account.');
                abort_if($admin->is_active && $admin->role === 'super_admin' && (!$data['is_active'] || $data['role'] !== 'super_admin') && PlatformAdmin::where('is_active', true)->where('role', 'super_admin')->count() <= 1, 422, 'Keep at least one active super administrator.');
            }
            $admin->fill(collect($data)->except(['password', 'password_confirmation'])->all());
            if (!empty($data['password'])) {
                $admin->password = Hash::make($data['password']);
                $admin->password_reset_at = now();
            }
            $admin->save();
            if (!$admin->is_active || !empty($data['password'])) $admin->tokens()->delete();
        });
        return ['item' => $admin->only(['id', 'name', 'email', 'role', 'is_active', 'created_at'])];
    }

    public function destroy(int $admin): array
    {
        $record = PlatformAdmin::findOrFail($admin);
        abort_if($record->id === Auth::guard('office')->id(), 422, 'You cannot deactivate your own account.');
        DB::transaction(function () use ($record) {
            PlatformAdmin::orderBy('id')->lockForUpdate()->get(['id']);
            $record->refresh();
            abort_if($record->role === 'super_admin' && $record->is_active && PlatformAdmin::where('is_active', true)->where('role', 'super_admin')->count() <= 1, 422, 'Keep at least one active super administrator.');
            $record->update(['is_active' => false]);
            $record->tokens()->delete();
        });
        return ['ok' => true];
    }

    public function activity(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'admin_id' => ['nullable', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 25, 50, 100])]]);
        $query = DB::table('office_activity_logs');
        if ($q = trim($data['q'] ?? '')) $query->where(fn ($f) => $f->where('admin_name', 'like', '%'.$q.'%')->orWhere('admin_email', 'like', '%'.$q.'%')->orWhere('action', 'like', '%'.$q.'%')->orWhere('path', 'like', '%'.$q.'%'));
        if (!empty($data['admin_id'])) $query->where('admin_id', $data['admin_id']);
        $result = $this->paginate($query->orderByDesc('id'), $data);
        $result['items'] = $result['items']->map(function ($item) {
            $item->details = json_decode($item->details, true);
            $item->created_at = \App\Support\AppTime::iso($item->created_at);
            return $item;
        });
        $result['admins'] = PlatformAdmin::orderBy('name')->get(['id', 'name']);
        return $result;
    }

    private function paginate($query, array $data): array
    {
        $total = (clone $query)->count(); $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage)); $page = min((int) ($data['page'] ?? 1), $lastPage);
        return ['items' => $query->offset(($page - 1) * $perPage)->limit($perPage)->get(), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
    }
}
