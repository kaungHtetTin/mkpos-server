<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchHelpArticles extends SupportTool
{
    protected string $name = 'search_help_articles';

    protected string $description = 'Search MKPOS and Shop help articles by short English or Burmese keywords; bounded pagination. Read only.';
}
