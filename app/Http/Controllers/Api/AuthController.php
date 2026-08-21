<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\AccessService;
use App\Services\SubscriptionService;
use App\Services\TrialSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private TrialSubscriptionService $trials)
    {
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        [$business, $user] = DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => trim($data['business_name']),
                'slug' => $this->uniqueSlug($data['business_name']),
                'status' => 'active',
                'timezone' => config('app.timezone'),
                'currency' => 'Ks',
            ]);

            $user = User::create([
                'business_id' => $business->id,
                'name' => trim($data['owner_name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'role' => 'owner',
                'is_active' => true,
            ]);

            $settings = [
                'shop_name' => $business->name,
                'currency' => $business->currency,
                'price_types' => 'Retail',
            ];
            foreach ($settings as $key => $value) {
                DB::table('settings')->insert([
                    'business_id' => $business->id,
                    'key' => $key,
                    'value' => $value,
                ]);
            }
            DB::table('price_type_rules')->insert([
                'business_id' => $business->id,
                'name' => 'Retail',
                'pricing_mode' => 'manual',
                'markup_percent' => 0,
                'rounding' => 1,
                'minimum_profit' => 0,
                'updated_at' => now(),
            ]);
            $this->trials->grant((int) $business->id);

            return [$business, $user];
        });

        if ($this->usesTokenAuth($request)) {
            return response()->json($this->tokenPayload($user, $business, $request), 201);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        return response()->json($this->payload($user, $business), 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $credentials['is_active'] = true;

        if ($this->usesTokenAuth($request)) {
            $user = User::with('business')->where('email', $credentials['email'])->where('is_active', true)->first();
            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                return response()->json(['message' => 'The email or password is incorrect.'], 422);
            }
            if (! $user->business || $user->business->status !== 'active') {
                return response()->json(['message' => 'This business account is not active.'], 403);
            }

            return $this->tokenPayload($user, $user->business, $request);
        }

        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user()->load('business');
        if (! $user->business || $user->business->status !== 'active') {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'This business account is not active.'], 403);
        }

        return $this->payload($user, $user->business);
    }

    public function me(Request $request): array
    {
        $user = $request->user('web')->load('business');

        return $this->payload($user, $user->business);
    }

    public function updateProfile(Request $request): array
    {
        $user = $request->user('web');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'string'],
            'password' => ['nullable', 'confirmed', Password::min(8), 'different:current_password'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->name = trim($data['name']);
        $user->email = Str::lower(trim($data['email']));
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
            $user->remember_token = Str::random(60);
        }
        $user->save();

        return $this->payload($user->fresh(), $user->business);
    }

    public function logout(Request $request): array
    {
        if ($request->bearerToken() && $request->user()?->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
            Auth::forgetGuards();

            return ['ok' => true];
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    private function payload(User $user, Business $business): array
    {
        $access = app(AccessService::class);

        return [
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'permissions' => $access->permissions($user),
            'role_name' => $access->roleName($user),
            'business' => $business->only(['id', 'name', 'slug', 'status', 'timezone', 'currency']),
            'subscription' => app(SubscriptionService::class)->status((int) $business->id),
        ];
    }

    private function usesTokenAuth(Request $request): bool
    {
        return Str::lower((string) $request->header('X-MKPOS-Auth')) === 'token';
    }

    private function tokenPayload(User $user, Business $business, Request $request): array
    {
        $client = preg_replace('/[^a-z0-9._-]+/i', '-', (string) $request->header('X-MKPOS-Client', 'portable')) ?: 'portable';
        $token = $user->createToken('mkpos-'.$client, ['*']);

        return array_merge($this->payload($user, $business), [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 2;
        while (Business::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
