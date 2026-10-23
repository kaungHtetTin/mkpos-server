<?php

use App\Http\Middleware\AuthenticatePaymentsMcp;
use App\Mcp\Servers\KnowledgeServer;
use App\Mcp\Servers\SubscriptionPaymentsServer;
use Laravel\Mcp\Facades\Mcp;

// Public knowledge only, including published plans and tutorials. No business login required.
Mcp::web('/mcp/knowledge', KnowledgeServer::class)
    ->middleware('throttle:60,1');

Mcp::local('mkpos-knowledge', KnowledgeServer::class);

Mcp::web('/mcp/account', \App\Mcp\Servers\CustomerAccountServer::class)
    ->middleware([\App\Http\Middleware\AuthenticateSupportMcp::class.':account', 'throttle:mcp-account']);
Mcp::web('/mcp/admin', \App\Mcp\Servers\SupportAdminServer::class)
    ->middleware([\App\Http\Middleware\AuthenticateSupportMcp::class.':admin', 'throttle:mcp-admin']);

\Illuminate\Support\Facades\Route::post('/mcp/evidence', [\App\Http\Controllers\McpSupportAccessController::class, 'evidence'])
    ->middleware([\App\Http\Middleware\AuthenticateSupportMcp::class.':account', 'throttle:mcp-evidence']);
\Illuminate\Support\Facades\Route::delete('/mcp/access', [\App\Http\Controllers\McpSupportAccessController::class, 'revoke'])
    ->middleware([\App\Http\Middleware\AuthenticateSupportMcp::class.':account', 'throttle:10,1']);
\Illuminate\Support\Facades\Route::delete('/mcp/admin/access', [\App\Http\Controllers\McpSupportAccessController::class, 'revoke'])
    ->middleware([\App\Http\Middleware\AuthenticateSupportMcp::class.':admin', 'throttle:10,1']);

// Private cross-business review data. Never register this tool on the public server.
Mcp::web('/mcp/subscription-payments', SubscriptionPaymentsServer::class)
    ->middleware(['throttle:30,1', AuthenticatePaymentsMcp::class]);
