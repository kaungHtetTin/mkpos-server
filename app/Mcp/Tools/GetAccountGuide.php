<?php

namespace App\Mcp\Tools;

use App\Support\ClientApps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Lang;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetAccountGuide extends Tool
{
    protected string $name = 'get_account_guide';

    protected string $description = 'Get step-by-step MKPOS business account instructions for login, register or password_reset in English or Burmese, with desktop and mobile links. Explains how to sign in, create a business account or recover a forgotten password. Never performs authentication or collects passwords/reset codes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['login', 'register', 'password_reset'])->required(),
            'language' => $schema->string()->enum(['en', 'my'])->description('Defaults to en.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:login,register,password_reset'],
            'language' => ['sometimes', 'string', 'in:en,my'],
        ]);
        $language = $data['language'] ?? 'en';
        $operation = $data['operation'];
        $content = Lang::get('mkpos_access.'.$operation, [], $language);
        $path = match ($operation) {
            'register' => 'signup',
            'password_reset' => 'forgot-password',
            default => 'login',
        };

        return Response::structured([
            'language' => $language,
            'operation' => $operation,
            'title' => $content['title'],
            'steps' => array_map(fn ($text, $index) => ['step' => $index + 1, 'instruction' => $text], $content['steps'], array_keys($content['steps'])),
            'notes' => $content['notes'],
            'links' => ['web' => ClientApps::webUrl($path), 'mobile' => ClientApps::mobileUrl($path)],
            'source_url' => route('documentation.show', ['topic' => 'start']),
            'answer_guidance' => 'Explain the ordered steps in the requested language. Use the link for the customer device; installed apps have their own sign-in screens. Never ask for passwords or reset codes, attempt authentication, or claim an account exists. Business recovery is for active owners; refer staff to their business owner.',
        ]);
    }
}
