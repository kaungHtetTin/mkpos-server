<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Lang;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMkposOverview extends Tool
{
    protected string $name = 'get_mkpos_overview';

    protected string $description = 'Explain what MKPOS is, who it is for and what customers can do with it. Returns a localized English or Burmese product overview, supported workflows, platforms, limitations and documentation links. Use for introduction and capability questions such as What is MKPOS?, What can MKPOS do?, MKPOS ဆိုတာဘာလဲ or ဘာတွေလုပ်လို့ရလဲ. For current prices use get_available_package_plans.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->enum(['en', 'my'])
                ->description('Customer language: en for English, my for Burmese. Defaults to en.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate(['language' => ['sometimes', 'string', 'in:en,my']]);
        $language = $data['language'] ?? 'en';
        $content = Lang::get('mkpos_knowledge', [], $language);
        $capabilities = [];

        foreach ($content['capabilities'] as $topic => $capability) {
            $capabilities[] = ['id' => $topic] + $capability + [
                'guide_url' => route('documentation.show', ['topic' => $topic]),
            ];
        }

        return Response::structured([
            'language' => $language,
            'product' => 'MKPOS',
            'title' => $content['title'],
            'summary' => $content['summary'],
            'audience' => $content['audience'],
            'capabilities' => $capabilities,
            'platforms' => $content['platforms'],
            'limitations' => $content['limitations'],
            'source_url' => route('landing'),
            'documentation_url' => route('documentation.index'),
            'pricing_tool' => 'get_available_package_plans',
            'answer_guidance' => 'Give a concise introduction in the requested language and expand on relevant capabilities when asked. Respect the limitations. Linked documentation is currently in English. Do not infer package entitlements, current prices or download availability from this overview. This tool does not access customer records or perform operations.',
        ]);
    }
}
