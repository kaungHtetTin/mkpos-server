<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Support\PlatformContacts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactSettingsController extends Controller
{
    public function show(): array
    {
        return PlatformContacts::all();
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'phone_numbers' => ['present', 'array', 'max:8'],
            'phone_numbers.*' => ['required', 'string', 'distinct', 'regex:/^\\+?[0-9][0-9 ()-]{5,24}$/'],
            'viber_numbers' => ['present', 'array', 'max:8'],
            'viber_numbers.*' => ['required', 'string', 'distinct', 'regex:/^\\+?[0-9][0-9 ()-]{5,24}$/'],
            'telegram_url' => ['nullable', 'url', 'starts_with:https://', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'community_label' => ['nullable', 'string', 'max:100'],
            'community_url' => ['nullable', 'url', 'starts_with:https://', 'max:255'],
        ]);

        if (! $data['phone_numbers'] && ! $data['viber_numbers'] && ! ($data['telegram_url'] ?? null)
            && ! ($data['email'] ?? null) && ! ($data['community_url'] ?? null)) {
            throw ValidationException::withMessages(['phone_numbers' => 'Add at least one contact method or community link.']);
        }

        DB::table('platform_contact_settings')->updateOrInsert(['id' => 1], [
            'phone_numbers' => json_encode(array_values(array_map('trim', $data['phone_numbers']))),
            'viber_numbers' => json_encode(array_values(array_map('trim', $data['viber_numbers']))),
            'telegram_url' => trim($data['telegram_url'] ?? '') ?: null,
            'email' => trim($data['email'] ?? '') ?: null,
            'community_label' => trim($data['community_label'] ?? '') ?: null,
            'community_url' => trim($data['community_url'] ?? '') ?: null,
            'updated_at' => now(),
        ]);

        return $this->show();
    }
}
