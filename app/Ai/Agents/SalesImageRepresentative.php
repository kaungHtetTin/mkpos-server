<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\HasStructuredOutput;

/** One provider call with the existing shop-scoped tools and confirmation boundaries. */
#[MaxSteps(8)]
#[MaxTokens(1200)]
class SalesImageRepresentative extends SalesRepresentative implements HasStructuredOutput
{
    public function schema(JsonSchema $schema): array
    {
        $fields = (new TelegramImageReader)->schema($schema);
        $fields['kind'] = $schema->string()->enum(['product', 'shop_payment', 'receipt', 'general', 'unreadable', 'unrelated', 'error_known', 'error_unknown'])->required();

        $fields['order_reference'] = $schema->string()->max(40)->description('This customer verified shop order reference, or empty when unknown.')->required();

        return $fields;
    }
}
