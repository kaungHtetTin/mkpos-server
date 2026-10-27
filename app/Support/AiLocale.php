<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

class AiLocale
{
    public static function text(string $key, string $language = 'en', array $values = []): string
    {
        return Lang::get('mkpos_ai.'.$key, $values, $language === 'my' ? 'my' : 'en');
    }
}
