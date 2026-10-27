<?php

use App\Http\Controllers\Ecommerce\AdminController;
use App\Http\Controllers\Ecommerce\ChatController;
use App\Http\Controllers\Ecommerce\ContactController;
use App\Http\Controllers\Ecommerce\CustomerAccountController;
use App\Http\Controllers\Ecommerce\CustomerFeaturesController;
use App\Http\Controllers\Ecommerce\CustomerOrderAlertController;
use App\Http\Controllers\Ecommerce\DeliveryZoneController;
use App\Http\Controllers\Ecommerce\OrderNotificationController;
use App\Http\Controllers\Ecommerce\OrderRequestController;
use App\Http\Controllers\Ecommerce\OwnerLocationController;
use App\Http\Controllers\Ecommerce\PaymentMethodController;
use App\Http\Controllers\Ecommerce\PromotionController;
use App\Http\Controllers\Ecommerce\ReviewController;
use App\Http\Controllers\Ecommerce\SalesAiActionController;
use App\Http\Controllers\Ecommerce\SalesAiController;
use App\Http\Controllers\Ecommerce\SalesTelegramController;
use App\Http\Controllers\Ecommerce\ShopController;
use App\Http\Controllers\Ecommerce\ShopCoverController;
use App\Http\Controllers\Ecommerce\ShopCustomerController;
use App\Http\Controllers\Ecommerce\ShopRadarController;
use App\Http\Controllers\Ecommerce\ShopReportController;
use App\Http\Controllers\McpSupportAccessController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Middleware\AuthenticatePlatformCustomer;
use App\Http\Middleware\EcommercePublicResponse;
use App\Http\Middleware\RequireShopOwnerLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('ecommerce')->middleware(['throttle:shop-public', EcommercePublicResponse::class])->group(function () {
    Route::get('/chat/config', [ChatController::class, 'config']);
    Route::middleware(AuthenticatePlatformCustomer::class.':optional')->group(function () {
        Route::post('/order-alerts/config', [CustomerOrderAlertController::class, 'config']);
        Route::post('/order-alerts/auth', [CustomerOrderAlertController::class, 'auth']);
        Route::post('/order-alerts/feed', [CustomerOrderAlertController::class, 'index']);
    });
    Route::middleware(AuthenticatePlatformCustomer::class)->group(function () {
        Route::get('/chat/inbox-config', [ChatController::class, 'customerInboxConfig']);
        Route::get('/chat/notifications', [ChatController::class, 'customerNotifications']);
        Route::get('/chat', [ChatController::class, 'customerIndex']);
        Route::get('/chat/{uuid}', [ChatController::class, 'customerShow']);
        Route::post('/chat/presence', [ChatController::class, 'customerAppPresence'])->middleware('throttle:60,1');
        Route::post('/stores/{slug}/chat', [ChatController::class, 'start'])->middleware('throttle:10,1');
        Route::get('/chat/{uuid}/messages', [ChatController::class, 'customerMessages']);
        Route::post('/chat/{uuid}/telegram-link', [SalesTelegramController::class, 'link'])->middleware('throttle:5,1');
        Route::get('/chat/{uuid}/products', [ChatController::class, 'customerProducts']);
        Route::get('/chat/{uuid}/messages/{id}/image', [ChatController::class, 'customerImage'])->whereNumber('id');
        Route::post('/chat/{uuid}/read', [ChatController::class, 'customerRead']);
        Route::post('/chat/{uuid}/presence', [ChatController::class, 'customerPresence'])->middleware('throttle:60,1');
        Route::post('/chat/{uuid}/messages', [ChatController::class, 'customerSend'])->middleware('throttle:60,1');
        Route::post('/chat/{uuid}/handoff', [ChatController::class, 'customerHandoff'])->middleware('throttle:20,1');
        Route::get('/chat/{uuid}/sales-actions/{id}', [SalesAiActionController::class, 'show'])->whereUuid('id');
        Route::post('/chat/{uuid}/sales-actions/{id}/review', [SalesAiActionController::class, 'review'])->whereUuid('id')->middleware('throttle:30,1');
        Route::post('/chat/{uuid}/sales-actions/{id}/confirm', [SalesAiActionController::class, 'confirm'])->whereUuid('id')->middleware('throttle:20,1');
        Route::post('/chat/{uuid}/sales-actions/{id}/cancel', [SalesAiActionController::class, 'cancel'])->whereUuid('id')->middleware('throttle:30,1');
        Route::get('/chat/{uuid}/sales-actions/{id}/image', [SalesAiActionController::class, 'image'])->whereUuid('id');
        Route::post('/chat/auth', [ChatController::class, 'customerAuth']);
    });
    Route::post('/customer/register', [CustomerAccountController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/customer/forgot-password', [PasswordResetController::class, 'requestCode'])->middleware('throttle:5,1');
    Route::post('/customer/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
    Route::post('/customer/login', [CustomerAccountController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/customer/avatars/{filename}', [CustomerAccountController::class, 'avatar'])->where('filename', '[a-f0-9-]+\.jpg');
    Route::prefix('customer')->middleware(AuthenticatePlatformCustomer::class)->group(function () {
        Route::post('/mcp/access', [McpSupportAccessController::class, 'customer'])->middleware('throttle:5,1');
        Route::get('/store-reports/{slug}', [ShopReportController::class, 'mine']);
        Route::post('/store-reports/{slug}', [ShopReportController::class, 'submit'])->middleware('throttle:5,1');
        Route::get('/me', [CustomerAccountController::class, 'me']);
        Route::get('/favorites', [CustomerFeaturesController::class, 'favorites']);
        Route::get('/favorite-keys', [CustomerFeaturesController::class, 'favoriteKeys']);
        Route::get('/notifications', [CustomerFeaturesController::class, 'notifications']);
        Route::post('/notifications/read', [CustomerFeaturesController::class, 'readNotifications']);
        Route::get('/stock-alerts', [CustomerFeaturesController::class, 'stockAlerts']);
        Route::delete('/stock-alerts/{id}', [CustomerFeaturesController::class, 'removeStockAlert'])->whereNumber('id');
        Route::match(['get', 'put', 'delete'], '/stock-alerts/{slug}/{id}', [CustomerFeaturesController::class, 'stockAlert'])->whereNumber('id');
        Route::delete('/favorites/{id}', [CustomerFeaturesController::class, 'removeFavorite'])->whereNumber('id');
        Route::match(['get', 'put', 'delete'], '/favorites/store/{slug}', [CustomerFeaturesController::class, 'favorite']);
        Route::get('/addresses', [CustomerFeaturesController::class, 'addresses']);
        Route::post('/addresses', [CustomerFeaturesController::class, 'saveAddress']);
        Route::put('/addresses/{id}', [CustomerFeaturesController::class, 'saveAddress'])->whereNumber('id');
        Route::delete('/addresses/{id}', [CustomerFeaturesController::class, 'deleteAddress'])->whereNumber('id');
        Route::put('/profile', [CustomerAccountController::class, 'profile']);
        Route::post('/profile/avatar', [CustomerAccountController::class, 'updateAvatar'])->middleware('throttle:10,1');
        Route::delete('/profile/avatar', [CustomerAccountController::class, 'deleteAvatar']);
        Route::post('/logout', [CustomerAccountController::class, 'logout']);
        Route::get('/orders', [CustomerAccountController::class, 'orders']);
        Route::get('/orders/{reference}', [CustomerAccountController::class, 'order']);
        Route::post('/orders/{reference}/buy-again', [CustomerAccountController::class, 'buyAgain']);
    });
    Route::get('/stores/{slug}', [ShopController::class, 'show']);
    foreach (['/stores/{slug}/reviews', '/stores/{slug}/products/{id}/reviews'] as $path) {
        Route::get($path, [ReviewController::class, 'index'])->where('id', '[1-9][0-9]*');
        Route::get($path.'/mine', [ReviewController::class, 'mine'])->middleware(AuthenticatePlatformCustomer::class)->where('id', '[1-9][0-9]*');
        Route::put($path.'/mine', [ReviewController::class, 'save'])->middleware([AuthenticatePlatformCustomer::class, 'throttle:10,1'])->where('id', '[1-9][0-9]*');
        Route::delete($path.'/mine', [ReviewController::class, 'destroy'])->middleware(AuthenticatePlatformCustomer::class)->where('id', '[1-9][0-9]*');
    }
    Route::get('/stores/{slug}/logo', [ShopController::class, 'logo']);
    Route::get('/stores/{slug}/cover', [ShopController::class, 'cover']);
    Route::get('/stores/{slug}/products', [ShopController::class, 'products']);
    Route::get('/stores/{slug}/products/{id}', [ShopController::class, 'product'])->whereNumber('id');
    Route::get('/stores/{slug}/products/{id}/related', [ShopController::class, 'related'])->whereNumber('id');
    Route::get('/stores/{slug}/products/{id}/photo', [ShopController::class, 'photo'])->whereNumber('id');
    Route::post('/stores/{slug}/quote', [ShopController::class, 'quote'])->middleware('throttle:shop-quote');
    Route::post('/stores/{slug}/checkout', [ShopController::class, 'checkout'])->middleware(['throttle:shop-checkout', AuthenticatePlatformCustomer::class.':optional']);
    Route::post('/tracking', [ShopController::class, 'tracking'])->middleware('throttle:shop-tracking');
    Route::post('/proofs', [ShopController::class, 'proof'])->middleware('throttle:shop-proofs');
    Route::post('/order-requests', [OrderRequestController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/marketplace/shops', [ShopController::class, 'discovery']);
    Route::get('/marketplace/radar', [ShopRadarController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/marketplace/categories', [ShopController::class, 'marketplaceCategories']);
    Route::get('/marketplace/products', [ShopController::class, 'marketplaceProducts']);
});

Route::prefix('shop-admin')->middleware(['auth:sanctum', 'business'])->group(function () {
    Route::get('/sales-ai/telegram', [SalesTelegramController::class, 'show']);
    Route::put('/sales-ai/telegram', [SalesTelegramController::class, 'update']);
    Route::post('/sales-ai/telegram/test', [SalesTelegramController::class, 'test'])->middleware('throttle:5,1');
    Route::post('/sales-ai/telegram/register', [SalesTelegramController::class, 'register'])->middleware('throttle:5,1');
    Route::get('/sales-ai/telegram/groups', [SalesTelegramController::class, 'groups']);
    Route::post('/sales-ai/telegram/groups', [SalesTelegramController::class, 'saveGroup']);
    Route::get('/sales-ai/telegram/groups/{group}', [SalesTelegramController::class, 'group'])->whereNumber('group');
    Route::put('/sales-ai/telegram/groups/{group}', [SalesTelegramController::class, 'saveGroup'])->whereNumber('group');
    Route::get('/sales-ai', [SalesAiController::class, 'show']);
    Route::put('/sales-ai', [SalesAiController::class, 'update']);
    Route::get('/sales-ai/wallet', [SalesAiController::class, 'wallet']);
    Route::get('/sales-ai/topups', [SalesAiController::class, 'topups']);
    Route::post('/sales-ai/topups', [SalesAiController::class, 'submit'])->middleware('throttle:5,1');
    Route::get('/sales-ai/topups/{id}', [SalesAiController::class, 'detail'])->whereNumber('id');
    Route::post('/sales-ai/topups/{id}/cancel', [SalesAiController::class, 'cancel'])->whereNumber('id');
    Route::get('/sales-ai/topups/{id}/screenshot', [SalesAiController::class, 'screenshot'])->whereNumber('id');
    Route::get('/owner-location', [OwnerLocationController::class, 'show']);
    Route::post('/owner-location', [OwnerLocationController::class, 'store']);
});

Route::prefix('shop-admin')->middleware(['auth:sanctum', 'business', RequireShopOwnerLocation::class])->group(function () {
    // Order resolution remains available after subscription expiry. Only configuration
    // and publication inherit the regular subscription write restrictions.
    Route::middleware(['subscription', 'module:ecommerce_settings'])->group(function () {
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'updateSettings']);
        Route::post('/cover', [ShopCoverController::class, 'store']);
        Route::delete('/cover', [ShopCoverController::class, 'destroy']);
        Route::get('/contacts', [ContactController::class, 'index']);
        Route::post('/contacts', [ContactController::class, 'store']);
        Route::put('/contacts/{contactId}', [ContactController::class, 'update'])->whereNumber('contactId');
        Route::delete('/contacts/{contactId}', [ContactController::class, 'destroy'])->whereNumber('contactId');
        Route::get('/delivery-zones', [DeliveryZoneController::class, 'index']);
        Route::post('/delivery-zones', [DeliveryZoneController::class, 'store']);
        Route::put('/delivery-zones/{zoneId}', [DeliveryZoneController::class, 'update']);
        Route::delete('/delivery-zones/{zoneId}', [DeliveryZoneController::class, 'destroy']);
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
        Route::put('/payment-methods/{methodId}', [PaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{methodId}', [PaymentMethodController::class, 'destroy']);
    });
    Route::middleware(['subscription', 'module:ecommerce_catalog'])->group(function () {
        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::get('/promotions/{id}', [PromotionController::class, 'show'])->whereNumber('id');
        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::post('/promotions/bulk-delete', [PromotionController::class, 'destroyMany']);
        Route::put('/promotions/{id}', [PromotionController::class, 'update'])->whereNumber('id');
        Route::delete('/promotions/{id}', [PromotionController::class, 'destroy'])->whereNumber('id');
        Route::get('/publications', [AdminController::class, 'publications']);
        Route::get('/publications/filters', [AdminController::class, 'publicationFilters']);
        Route::get('/publications/{id}', [AdminController::class, 'publication'])->whereNumber('id');
        Route::get('/publications/{id}/photo', [AdminController::class, 'publicationPhoto'])->whereNumber('id');
        Route::put('/publications/bulk', [AdminController::class, 'publishFiltered']);
        Route::put('/publications/{id}', [AdminController::class, 'publish'])->whereNumber('id');
    });
    Route::middleware('module:ecommerce_orders')->group(function () {
        Route::get('/order-notifications/config', [OrderNotificationController::class, 'config']);
        Route::post('/order-notifications/auth', [OrderNotificationController::class, 'auth']);
        Route::get('/order-notifications', [OrderNotificationController::class, 'index']);
        Route::get('/reviews', [ReviewController::class, 'adminIndex']);
        Route::get('/reviews/{id}', [ReviewController::class, 'adminShow'])->whereNumber('id');
        Route::get('/reviews/{id}/photo', [ReviewController::class, 'adminPhoto'])->whereNumber('id');
        Route::put('/reviews/{id}/reply', [ReviewController::class, 'reply'])->middleware('module:ecommerce_process')->whereNumber('id');
        Route::get('/chat/config', [ChatController::class, 'ownerConfig']);
        Route::get('/chat/notifications', [ChatController::class, 'ownerNotifications']);
        Route::get('/chat/unread', [ChatController::class, 'unread']);
        Route::post('/chat/presence', [ChatController::class, 'ownerPresence'])->middleware('throttle:60,1');
        Route::get('/chat', [ChatController::class, 'index']);
        Route::get('/chat/{uuid}/messages', [ChatController::class, 'ownerMessages']);
        Route::post('/chat/handoff-all', [ChatController::class, 'ownerHandoffAll'])->middleware(['module:ecommerce_process', 'throttle:10,1']);
        Route::post('/chat/{uuid}/handoff', [ChatController::class, 'ownerHandoff'])->middleware('throttle:20,1');
        Route::get('/chat/{uuid}/products', [ChatController::class, 'ownerProducts']);
        Route::get('/chat/{uuid}/messages/{id}/image', [ChatController::class, 'ownerImage'])->whereNumber('id');
        Route::post('/chat/{uuid}/read', [ChatController::class, 'ownerRead']);
        Route::post('/chat/auth', [ChatController::class, 'ownerAuth']);
        Route::post('/chat/{uuid}/messages', [ChatController::class, 'ownerSend'])->middleware(['module:ecommerce_process', 'throttle:60,1']);
        Route::post('/chat/{uuid}/messages/{id}/retry-telegram', [ChatController::class, 'ownerRetryTelegram'])->whereNumber('id')->middleware(['module:ecommerce_process', 'throttle:30,1']);
        Route::get('/customers', [ShopCustomerController::class, 'index']);
        Route::get('/customers/{customerKey}', [ShopCustomerController::class, 'show']);
        Route::get('/customers/{customerKey}/history', [ShopCustomerController::class, 'history']);
        Route::get('/customers/{customerKey}/orders', [ShopCustomerController::class, 'orders']);
        Route::post('/customers/{customerKey}/chat', [ShopCustomerController::class, 'chat'])->middleware(['module:ecommerce_process', 'throttle:60,1']);
        Route::put('/customers/{customerKey}/block', [ShopCustomerController::class, 'block'])->middleware('module:ecommerce_process');
        Route::post('/customers/{customerKey}/credit-contact', [ShopCustomerController::class, 'creditContact'])->middleware(['subscription', 'module:customers']);
        Route::get('/customers/{customerKey}/credit-options', [ShopCustomerController::class, 'creditOptions'])->middleware(['subscription', 'module:customers']);
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::get('/orders/{id}', [AdminController::class, 'order'])->whereNumber('id');
        Route::get('/orders/{id}/items/{productId}/photo', [AdminController::class, 'orderItemPhoto'])->whereNumber('id')->whereNumber('productId');
        Route::get('/report', [AdminController::class, 'report']);
    });
    Route::post('/orders/{id}/transition', [AdminController::class, 'transition'])->middleware('module:ecommerce_process')->whereNumber('id');
    Route::post('/orders/{id}/requests/{requestId}', [OrderRequestController::class, 'resolve'])->middleware('module:ecommerce_process')->whereNumber('id')->whereNumber('requestId');
    Route::get('/orders/{id}/requests/{requestId}/photo', [OrderRequestController::class, 'photo'])->middleware('module:ecommerce_orders')->whereNumber('id')->whereNumber('requestId');
    Route::post('/orders/{id}/reopen', [AdminController::class, 'reopen'])->middleware('module:ecommerce_process')->whereNumber('id');
    Route::post('/orders/{id}/payments/{action}', [AdminController::class, 'payment'])
        ->whereIn('action', ['confirm', 'reject'])->middleware('module:ecommerce_payments')->whereNumber('id');
    Route::post('/orders/{id}/payments/refund', [AdminController::class, 'payment'])
        ->defaults('action', 'refund')->middleware('module:ecommerce_refunds')->whereNumber('id');
    Route::get('/orders/{id}/proofs/{proofId}', [AdminController::class, 'proof'])->middleware('module:ecommerce_payments')->whereNumber('id')->whereNumber('proofId');
});

Route::prefix('office/ecommerce')->middleware('office.auth')->group(function () {
    Route::get('/reports', [ShopReportController::class, 'index']);
    Route::get('/reports/{id}', [ShopReportController::class, 'show'])->whereNumber('id');
    Route::put('/reports/{id}', [ShopReportController::class, 'review'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/stores', function (Request $request) {
        $limit = max(1, min(100, (int) $request->query('limit', 30)));
        $rows = DB::table('storefront_settings')->orderBy('id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)
            ->get(['business_id', 'slug', 'display_name', 'storefront_enabled', 'marketplace_enabled', 'marketplace_suspended', 'marketplace_suspension_reason']);

        return ['items' => $rows->take($limit)->values(), 'has_more' => $rows->count() > $limit];
    });
    Route::put('/stores/{businessId}/marketplace', function (Request $request, int $businessId) {
        $data = $request->validate(['marketplace_suspended' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        abort_unless(DB::table('storefront_settings')->where('business_id', $businessId)->exists(), 404);
        DB::table('storefront_settings')->where('business_id', $businessId)->update(['marketplace_suspended' => $data['marketplace_suspended'],
            'marketplace_suspension_reason' => $data['reason'], 'updated_at' => now()]);

        return ['ok' => true];
    })->whereNumber('businessId');
});
