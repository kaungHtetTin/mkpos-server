<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $credentials = [
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
            'is_active' => true,
        ];

        if ($this->wantsToken($request)) {
            $admin = PlatformAdmin::where('email', $credentials['email'])
                ->where('is_active', true)
                ->first();
            if (! $admin || ! Hash::check($credentials['password'], $admin->password)) {
                return response()->json(['message' => 'The email or password is incorrect.'], 422);
            }

            return $this->payload($admin, $this->issueToken($request, $admin));
        }

        if (! Auth::guard('office')->attempt($credentials, (bool) ($data['remember'] ?? false))) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        $request->session()->regenerate();
        $request->session()->put('office_password_reset_version', hash('sha256', Auth::guard('office')->user()->password));

        return $this->payload();
    }

    public function me(): array
    {
        return $this->payload();
    }

    public function updateProfile(Request $request): array
    {
        $admin = Auth::guard('office')->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_admins', 'email')->ignore($admin->id)],
            'current_password' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $admin->name = trim($data['name']);
        $admin->email = Str::lower(trim($data['email']));
        $passwordChanged = ! empty($data['password']);
        if ($passwordChanged) {
            app(\App\Services\PasswordSecurity::class)->change($admin, $data['password']);
        } else {
            $admin->save();
        }

        $token = null;
        if ($passwordChanged && $request->bearerToken()) {
            $token = $this->issueToken($request, $admin);
        }
        if ($request->hasSession()) {
            if ($passwordChanged) $request->session()->regenerate();
            $request->session()->put('office_password_reset_version', hash('sha256', $admin->password));
        }

        return $this->payload($admin, $token);
    }

    public function logout(Request $request): array
    {
        if ($request->bearerToken()) {
            $request->user('sanctum')?->currentAccessToken()?->delete();
            Auth::forgetGuards();

            return ['ok' => true];
        }

        Auth::guard('office')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    private function payload(?PlatformAdmin $admin = null, ?string $token = null): array
    {
        $payload = ['timezone' => config('app.timezone'), 'admin' => ($admin ?? Auth::guard('office')->user())->only(['id', 'name', 'email'])];
        if ($token) {
            $payload['access_token'] = $token;
            $payload['token_type'] = 'Bearer';
        }

        return $payload;
    }

    private function wantsToken(Request $request): bool
    {
        return $request->header('X-MKPOS-Auth') === 'token';
    }

    private function issueToken(Request $request, PlatformAdmin $admin): string
    {
        $client = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $request->header('X-MKPOS-Client', 'office'));

        return $admin->createToken('office-'.trim($client, '-'), ['office'], now()->addMinutes((int) config('sanctum.expiration')))->plainTextToken;
    }
}
