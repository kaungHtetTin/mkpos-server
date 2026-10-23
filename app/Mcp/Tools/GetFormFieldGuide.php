<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetFormFieldGuide extends SupportTool
{
    protected string $name = 'get_form_field_guide';

    protected string $description = 'Explain MKPOS or Shop form fields: meaning, required/optional/conditional status, validation, safe examples and related-field rules in English or Myanmar. Omit form to list supported forms. Specify app, form and optionally field (API key or displayed English/Myanmar label), and mode create/edit. Supports nested item fields such as items.0.quantity. Does not accept actual form values, validate a live record, submit a form or request passwords/OTPs.';
}
