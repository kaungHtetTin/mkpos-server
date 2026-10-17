<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShopContacts
{
    public const PLATFORMS = ['phone', 'email', 'facebook', 'tiktok', 'youtube', 'telegram', 'viber', 'instagram', 'whatsapp'];

    public static function validate(Request $request): array
    {
        $value = $request->input('value');
        $label = $request->input('label', '');
        $request->merge(['value' => is_string($value) ? trim($value) : $value, 'label' => is_string($label) ? trim($label) : ($label ?? '')]);
        $request->validate(['platform' => ['required', Rule::in(self::PLATFORMS)], 'value' => ['required', 'string', 'max:500'], 'label' => ['nullable', 'string', 'max:100']]);
        $platform = $request->input('platform');
        $rules = ['required', 'string', 'max:500'];
        if ($platform === 'email') $rules[] = 'email';
        elseif ($platform === 'phone') $rules[] = function ($attribute, $value, $fail) {
            if (! preg_match('/^\+?[0-9]{3,20}$/', preg_replace('/[ ()-]/', '', $value))) $fail('Enter a valid phone number.');
        };
        elseif (in_array($platform, ['viber', 'whatsapp']) && ! preg_match('/^https?:\/\//i', $request->input('value'))) {
            $rules[] = function ($attribute, $value, $fail) {
                $number = preg_replace('/[ ()-]/', '', $value);
                if (! preg_match('/^\+[1-9][0-9]{6,14}$/', $number)) $fail('Enter an international phone number starting with + and country code, or a full contact link.');
            };
        } else {
            $rules[] = 'url';
            $rules[] = 'regex:/^https?:\/\//i';
        }
        return $request->validate(['platform' => ['required', Rule::in(self::PLATFORMS)], 'label' => ['nullable', 'string', 'max:100'], 'value' => $rules]);
    }

    public static function view(object $contact): array
    {
        $value = $contact->value;
        $url = null;
        if ($contact->platform === 'phone') $url = 'tel:'.preg_replace('/[ ()-]/', '', $value);
        elseif ($contact->platform === 'email') $url = 'mailto:'.$value;
        elseif (preg_match('/^https?:\/\//i', $value)) $url = $value;
        elseif ($contact->platform === 'whatsapp') $url = 'https://wa.me/'.preg_replace('/\D/', '', $value);
        return ['id' => $contact->id, 'platform' => $contact->platform, 'label' => $contact->label, 'value' => $value, 'url' => $url];
    }

    public static function list(int $businessId): array
    {
        return DB::table('shop_contacts')->where('business_id', $businessId)->orderBy('id')->get()->map([self::class, 'view'])->all();
    }
}
