<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
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
        if (! Auth::guard('office')->attempt($credentials, (bool) ($data['remember'] ?? false))) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        $request->session()->regenerate();

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
        if (! empty($data['password'])) {
            $admin->password = Hash::make($data['password']);
            $admin->setRememberToken(Str::random(60));
        }
        $admin->save();

        return $this->payload();
    }

    public function logout(Request $request): array
    {
        Auth::guard('office')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    private function payload(): array
    {
        return ['admin' => Auth::guard('office')->user()->only(['id', 'name', 'email'])];
    }
}
