<?php

use App\Http\Controllers\McpSupportAccessController;
use App\Http\Middleware\AuthenticatePaymentsMcp;
use App\Http\Middleware\AuthenticateSupportMcp;
use App\Mcp\Servers\CustomerAccountServer;
use App\Mcp\Servers\KnowledgeServer;
use App\Mcp\Servers\SubscriptionPaymentsServer;
use App\Mcp\Servers\SupportAdminServer;
use App\Mcp\Servers\SystemAgentServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Public knowledge only, including published plans and tutorials. No business login required.
Mcp::web('/mcp/knowledge', KnowledgeServer::class)
    ->middleware('throttle:120,1');

Mcp::local('mkpos-knowledge', KnowledgeServer::class);

Mcp::web('/mcp/account', CustomerAccountServer::class)
    ->middleware([AuthenticateSupportMcp::class.':account', 'throttle:mcp-account']);
Mcp::web('/mcp/admin', SupportAdminServer::class)
    ->middleware([AuthenticateSupportMcp::class.':admin', 'throttle:mcp-admin']);
Mcp::web('/mcp/system-agent', SystemAgentServer::class)
    ->middleware([AuthenticateSupportMcp::class.':account', 'throttle:mcp-account']);

Route::post('/mcp/evidence', [McpSupportAccessController::class, 'evidence'])
    ->middleware([AuthenticateSupportMcp::class.':account', 'throttle:mcp-evidence']);
Route::delete('/mcp/access', [McpSupportAccessController::class, 'revoke'])
    ->middleware([AuthenticateSupportMcp::class.':account', 'throttle:120,1']);
Route::delete('/mcp/admin/access', [McpSupportAccessController::class, 'revoke'])
    ->middleware([AuthenticateSupportMcp::class.':admin', 'throttle:120,1']);

// Private cross-business review data. Never register this tool on the public server.
Mcp::web('/mcp/subscription-payments', SubscriptionPaymentsServer::class)
    ->middleware(['throttle:120,1', AuthenticatePaymentsMcp::class]);
