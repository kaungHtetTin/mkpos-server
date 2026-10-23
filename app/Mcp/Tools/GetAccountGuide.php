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
            'app' => $schema->string()->enum(['mkpos', 'shop'])->description('Defaults to mkpos business account. Shop customers use a separate account.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:login,register,password_reset'],
            'language' => ['sometimes', 'string', 'in:en,my'],
            'app' => ['sometimes', 'string', 'in:mkpos,shop'],
        ]);
        $language = $data['language'] ?? 'en';
        $operation = $data['operation'];
        if (($data['app'] ?? 'mkpos') === 'shop') {
            $path = ['login' => 'login', 'register' => 'register', 'password_reset' => 'forgot-password'][$operation];
            $base = rtrim(config('clients.shop_url') ?: 'https://shop.mkposmyanmar.com', '/');
            $steps = $language === 'my' ? [
                'Shop app ၏ Me menu ကို ဖွင့်ပါ။ Business owner account နှင့် Shop customer account သီးခြားဖြစ်ပါသည်။',
                ['login' => 'အကောင့်ဝင်ရန် Email နှင့် Password ကို app ထဲတွင်သာ ဖြည့်ပါ။', 'register' => 'Create account ကိုနှိပ်ပြီး အမည်၊ Email၊ Password နှင့် အတည်ပြု Password ဖြည့်ပါ။', 'password_reset' => 'Forgot password ကိုနှိပ်ပြီး မိမိ Shop account Email ဖြင့် reset code တောင်းပါ။'][$operation],
                'App တွင် ဖော်ပြသော အဆင့်များအတိုင်း ဆက်လုပ်ပါ။ Password နှင့် reset code ကို chatbot သို့ မပို့ပါနှင့်။',
            ] : [
                'Open Me in the Shop app. A Shop customer account is separate from a business owner account.',
                ['login' => 'Enter your Shop email and password in the app and select Sign in.', 'register' => 'Select Create account and enter your name, email, password and matching confirmation.', 'password_reset' => 'Select Forgot password and request a reset code for your Shop email. Follow the app recovery steps.'][$operation],
                'Enter passwords and reset codes only in the app, never in this chat. A generic recovery message does not confirm an account exists or that mail was delivered.',
            ];
            return Response::structured(['language' => $language, 'app' => 'shop', 'operation' => $operation,
                'steps' => array_map(fn ($text, $index) => ['step' => $index + 1, 'instruction' => $text], $steps, array_keys($steps)),
                'links' => ['shop' => $base.'/#/account/'.$path],
                'answer_guidance' => 'Explain these steps in the requested language. Do not authenticate, request credentials, or claim account existence.']);
        }
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
