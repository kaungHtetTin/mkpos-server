<?php

use App\Mcp\Servers\KnowledgeServer;
use Laravel\Mcp\Facades\Mcp;

// Public information only, matching the public /plans page. No business login required.
Mcp::web('/mcp/knowledge', KnowledgeServer::class)
    ->middleware('throttle:60,1');

Mcp::local('mkpos-knowledge', KnowledgeServer::class);
