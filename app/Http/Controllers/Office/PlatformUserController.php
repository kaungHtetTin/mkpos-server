<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\PlatformCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformUserController extends Controller
{
    public function index(Request $request): array
    {
        $dateEndRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('registered_from')) $dateEndRules[] = 'after_or_equal:registered_from';
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'phone' => ['nullable', Rule::in(['all', 'present', 'missing'])],
            'address' => ['nullable', Rule::in(['all', 'present', 'missing'])],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => $dateEndRules,
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $query = PlatformCustomer::query();
        if ($q = trim($data['q'] ?? '')) {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%'));
        }
        if (in_array($data['status'] ?? 'all', ['active', 'inactive'], true)) {
            $query->where('is_active', $data['status'] === 'active');
        }
        foreach (['phone', 'address'] as $field) {
            if (in_array($data[$field] ?? 'all', ['present', 'missing'], true)) {
                $query->whereRaw("TRIM(COALESCE($field, '')) ".($data[$field] === 'present' ? "<> ''" : "= ''"));
            }
        }
        if (!empty($data['registered_from'])) $query->whereDate('created_at', '>=', $data['registered_from']);
        if (!empty($data['registered_to'])) $query->whereDate('created_at', '<=', $data['registered_to']);
        $total = (clone $query)->count();
        $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        $sort = $data['sort'] ?? 'newest';
        if ($sort === 'name') $query->orderBy('name')->orderBy('id');
        else $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc')->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc');
        return ['items' => $query->forPage($page, $perPage)->get(),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
    }

    public function show(PlatformCustomer $platformUser): PlatformCustomer
    {
        return $platformUser;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['password'] = Hash::make($data['password']);

        return response()->json(PlatformCustomer::create($data), 201);
    }

    public function update(Request $request, PlatformCustomer $platformUser): PlatformCustomer
    {
        $data = $this->validated($request, $platformUser);
        return DB::transaction(function () use ($platformUser, $data) {
            $platformUser = PlatformCustomer::whereKey($platformUser->id)->lockForUpdate()->firstOrFail();
            $credentialsChanged = ! empty($data['password']) || $platformUser->email !== $data['email'];
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
            $platformUser->fill($data);
            if ($credentialsChanged || ! $platformUser->is_active) {
                DB::table('platform_customer_tokens')->where('platform_customer_id', $platformUser->id)->delete();
            }
            $platformUser->save();
            if (array_key_exists('address', $data)) {
                DB::table('shop_customer_addresses')->where('customer_id', $platformUser->id)->where('is_default', true)
                    ->update(['address' => $data['address'] ?? '', 'updated_at' => now()]);
            }

            return $platformUser;
        });
    }

    public function destroy(PlatformCustomer $platformUser): array
    {
        DB::transaction(function () use ($platformUser) {
            $platformUser = PlatformCustomer::whereKey($platformUser->id)->lockForUpdate()->firstOrFail();
            $paths = DB::table('shop_chat_messages')->whereIn('thread_id',
                DB::table('shop_chat_threads')->where('platform_customer_id', $platformUser->id)->select('id'))
                ->whereNotNull('image_path')->pluck('image_path')->all();
            if ($platformUser->avatar_path) $paths[] = $platformUser->avatar_path;
            // Foreign keys retain orders and remove customer-owned account data.
            $platformUser->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        });

        return ['ok' => true];
    }

    private function validated(Request $request, ?PlatformCustomer $user = null): array
    {
        foreach (['name', 'email'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => $field === 'email' ? Str::lower(trim($request->input($field))) : trim($request->input($field))]);
            }
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_customers', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:128', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
