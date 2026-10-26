<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiManager;

class AiProviderCredentials
{
    public function key(): ?string
    {
        if (Schema::hasColumn('ai_assistant_settings', 'api_key_encrypted')) {
            $encrypted = DB::table('ai_assistant_settings')->where('id', 1)->value('api_key_encrypted');
            if ($encrypted) {
                try {
                    return Crypt::decryptString($encrypted);
                } catch (DecryptException) {
                    // Fail closed if a saved key cannot be read. Never expose ciphertext.
                    return null;
                }
            }
        }

        return config('ai.providers.deepseek.environment_key', config('ai.providers.deepseek.key'));
    }

    public function replace(#[\SensitiveParameter] string $key): void
    {
        DB::table('ai_assistant_settings')->where('id', 1)->update(['api_key_encrypted' => Crypt::encryptString($key), 'updated_at' => now('UTC')]);
    }

    public function activate(): void
    {
        if (! array_key_exists('environment_key', config('ai.providers.deepseek', []))) {
            config()->set('ai.providers.deepseek.environment_key', config('ai.providers.deepseek.key'));
        }
        config()->set('ai.providers.deepseek.key', $this->key());
        app(AiManager::class)->forgetInstance('deepseek');
    }
}
