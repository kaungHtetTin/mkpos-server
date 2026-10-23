<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetHelpArticle extends SupportTool
{
    protected string $name = 'get_help_article';

    protected string $description = 'Read a documented MKPOS or Shop usage guide by article_id. Returns source and actual guide text.';
}
