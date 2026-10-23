<?php

use App\Http\Middleware\AuthenticatePaymentsMcp;
use App\Mcp\Servers\KnowledgeServer;
use App\Mcp\Servers\SubscriptionPaymentsServer;
use Laravel\Mcp\Facades\Mcp;

// Public information only, matching the public /plans page. No business login required.
Mcp::web('/mcp/knowledge', KnowledgeServer::class)
    ->middleware('throttle:60,1');

Mcp::local('mkpos-knowledge', KnowledgeServer::class);

// Private cross-business review data. Never register this tool on the public server.
Mcp::web('/mcp/subscription-payments', SubscriptionPaymentsServer::class)
    ->middleware(['throttle:30,1', AuthenticatePaymentsMcp::class]);
