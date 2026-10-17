<?php

use App\Http\Controllers\Ecommerce\AdminController;
use App\Http\Controllers\Ecommerce\ShopController;
use App\Http\Controllers\Ecommerce\CustomerAccountController;
use App\Http\Middleware\AuthenticatePlatformCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('ecommerce')->middleware(['throttle:shop-public', \App\Http\Middleware\EcommercePublicResponse::class])->group(function () {
    Route::get('/chat/config', [\App\Http\Controllers\Ecommerce\ChatController::class, 'config']);
    Route::middleware(AuthenticatePlatformCustomer::class.':optional')->group(function () {
        Route::post('/order-alerts/config', [\App\Http\Controllers\Ecommerce\CustomerOrderAlertController::class, 'config']);
        Route::post('/order-alerts/auth', [\App\Http\Controllers\Ecommerce\CustomerOrderAlertController::class, 'auth']);
        Route::post('/order-alerts/feed', [\App\Http\Controllers\Ecommerce\CustomerOrderAlertController::class, 'index']);
    });
    Route::middleware(AuthenticatePlatformCustomer::class)->group(function () {
        Route::get('/chat/notifications', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerNotifications']);
        Route::get('/chat', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerIndex']);
        Route::get('/chat/{uuid}', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerShow']);
        Route::post('/chat/presence', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerAppPresence'])->middleware('throttle:60,1');
        Route::post('/stores/{slug}/chat', [\App\Http\Controllers\Ecommerce\ChatController::class, 'start'])->middleware('throttle:10,1');
        Route::get('/chat/{uuid}/messages', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerMessages']);
        Route::get('/chat/{uuid}/products', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerProducts']);
        Route::get('/chat/{uuid}/messages/{id}/image', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerImage'])->whereNumber('id');
        Route::post('/chat/{uuid}/read', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerRead']);
        Route::post('/chat/{uuid}/presence', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerPresence'])->middleware('throttle:60,1');
        Route::post('/chat/{uuid}/messages', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerSend'])->middleware('throttle:60,1');
        Route::post('/chat/auth', [\App\Http\Controllers\Ecommerce\ChatController::class, 'customerAuth']);
    });
    Route::post('/customer/register', [CustomerAccountController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/customer/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'requestCode'])->middleware('throttle:5,1');
    Route::post('/customer/reset-password', [\App\Http\Controllers\PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
    Route::post('/customer/login', [CustomerAccountController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/customer/avatars/{filename}', [CustomerAccountController::class, 'avatar'])->where('filename', '[a-f0-9-]+\.jpg');
    Route::prefix('customer')->middleware(AuthenticatePlatformCustomer::class)->group(function () {
        Route::get('/store-reports/{slug}', [\App\Http\Controllers\Ecommerce\ShopReportController::class, 'mine']);
        Route::post('/store-reports/{slug}', [\App\Http\Controllers\Ecommerce\ShopReportController::class, 'submit'])->middleware('throttle:5,1');
        Route::get('/me', [CustomerAccountController::class, 'me']);
        Route::get('/favorites', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'favorites']);
        Route::get('/favorite-keys', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'favoriteKeys']);
        Route::get('/notifications', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'notifications']);
        Route::post('/notifications/read', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'readNotifications']);
        Route::get('/stock-alerts', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'stockAlerts']);
        Route::delete('/stock-alerts/{id}', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'removeStockAlert'])->whereNumber('id');
        Route::match(['get', 'put', 'delete'], '/stock-alerts/{slug}/{id}', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'stockAlert'])->whereNumber('id');
        Route::delete('/favorites/{id}', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'removeFavorite'])->whereNumber('id');
        Route::match(['get', 'put', 'delete'], '/favorites/store/{slug}', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'favorite']);
        Route::get('/addresses', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'addresses']);
        Route::post('/addresses', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'saveAddress']);
        Route::put('/addresses/{id}', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'saveAddress'])->whereNumber('id');
        Route::delete('/addresses/{id}', [\App\Http\Controllers\Ecommerce\CustomerFeaturesController::class, 'deleteAddress'])->whereNumber('id');
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
        Route::get($path, [\App\Http\Controllers\Ecommerce\ReviewController::class, 'index'])->where('id', '[1-9][0-9]*');
        Route::get($path.'/mine', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'mine'])->middleware(AuthenticatePlatformCustomer::class)->where('id', '[1-9][0-9]*');
        Route::put($path.'/mine', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'save'])->middleware([AuthenticatePlatformCustomer::class, 'throttle:10,1'])->where('id', '[1-9][0-9]*');
        Route::delete($path.'/mine', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'destroy'])->middleware(AuthenticatePlatformCustomer::class)->where('id', '[1-9][0-9]*');
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
    Route::post('/order-requests', [\App\Http\Controllers\Ecommerce\OrderRequestController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/marketplace/shops', [ShopController::class, 'discovery']);
    Route::get('/marketplace/radar', [\App\Http\Controllers\Ecommerce\ShopRadarController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/marketplace/categories', [ShopController::class, 'marketplaceCategories']);
    Route::get('/marketplace/products', [ShopController::class, 'marketplaceProducts']);
});

Route::prefix('shop-admin')->middleware(['auth:sanctum', 'business'])->group(function () {
    Route::get('/owner-location', [\App\Http\Controllers\Ecommerce\OwnerLocationController::class, 'show']);
    Route::post('/owner-location', [\App\Http\Controllers\Ecommerce\OwnerLocationController::class, 'store']);
});

Route::prefix('shop-admin')->middleware(['auth:sanctum', 'business', \App\Http\Middleware\RequireShopOwnerLocation::class])->group(function () {
    // Order resolution remains available after subscription expiry. Only configuration
    // and publication inherit the regular subscription write restrictions.
    Route::middleware(['subscription', 'module:ecommerce_settings'])->group(function () {
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'updateSettings']);
        Route::post('/cover', [\App\Http\Controllers\Ecommerce\ShopCoverController::class, 'store']);
        Route::delete('/cover', [\App\Http\Controllers\Ecommerce\ShopCoverController::class, 'destroy']);
        Route::get('/contacts', [\App\Http\Controllers\Ecommerce\ContactController::class, 'index']);
        Route::post('/contacts', [\App\Http\Controllers\Ecommerce\ContactController::class, 'store']);
        Route::put('/contacts/{contactId}', [\App\Http\Controllers\Ecommerce\ContactController::class, 'update'])->whereNumber('contactId');
        Route::delete('/contacts/{contactId}', [\App\Http\Controllers\Ecommerce\ContactController::class, 'destroy'])->whereNumber('contactId');
        Route::get('/delivery-zones', [\App\Http\Controllers\Ecommerce\DeliveryZoneController::class, 'index']);
        Route::post('/delivery-zones', [\App\Http\Controllers\Ecommerce\DeliveryZoneController::class, 'store']);
        Route::put('/delivery-zones/{zoneId}', [\App\Http\Controllers\Ecommerce\DeliveryZoneController::class, 'update']);
        Route::delete('/delivery-zones/{zoneId}', [\App\Http\Controllers\Ecommerce\DeliveryZoneController::class, 'destroy']);
        Route::get('/payment-methods', [\App\Http\Controllers\Ecommerce\PaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [\App\Http\Controllers\Ecommerce\PaymentMethodController::class, 'store']);
        Route::put('/payment-methods/{methodId}', [\App\Http\Controllers\Ecommerce\PaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{methodId}', [\App\Http\Controllers\Ecommerce\PaymentMethodController::class, 'destroy']);
    });
    Route::middleware(['subscription', 'module:ecommerce_catalog'])->group(function () {
        Route::get('/promotions', [\App\Http\Controllers\Ecommerce\PromotionController::class, 'index']);
        Route::get('/promotions/{id}', [\App\Http\Controllers\Ecommerce\PromotionController::class, 'show'])->whereNumber('id');
        Route::post('/promotions', [\App\Http\Controllers\Ecommerce\PromotionController::class, 'store']);
        Route::post('/promotions/bulk-delete', [\App\Http\Controllers\Ecommerce\PromotionController::class, 'destroyMany']);
        Route::put('/promotions/{id}', [\App\Http\Controllers\Ecommerce\PromotionController::class, 'update'])->whereNumber('id');
        Route::delete('/promotions/{id}', [\App\Http\Controllers\Ecommerce\PromotionController::class, 'destroy'])->whereNumber('id');
        Route::get('/publications', [AdminController::class, 'publications']);
        Route::get('/publications/filters', [AdminController::class, 'publicationFilters']);
        Route::get('/publications/{id}', [AdminController::class, 'publication'])->whereNumber('id');
        Route::get('/publications/{id}/photo', [AdminController::class, 'publicationPhoto'])->whereNumber('id');
        Route::put('/publications/bulk', [AdminController::class, 'publishFiltered']);
        Route::put('/publications/{id}', [AdminController::class, 'publish'])->whereNumber('id');
    });
    Route::middleware('module:ecommerce_orders')->group(function () {
        Route::get('/order-notifications/config', [\App\Http\Controllers\Ecommerce\OrderNotificationController::class, 'config']);
        Route::post('/order-notifications/auth', [\App\Http\Controllers\Ecommerce\OrderNotificationController::class, 'auth']);
        Route::get('/order-notifications', [\App\Http\Controllers\Ecommerce\OrderNotificationController::class, 'index']);
        Route::get('/reviews', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'adminIndex']);
        Route::get('/reviews/{id}', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'adminShow'])->whereNumber('id');
        Route::get('/reviews/{id}/photo', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'adminPhoto'])->whereNumber('id');
        Route::put('/reviews/{id}/reply', [\App\Http\Controllers\Ecommerce\ReviewController::class, 'reply'])->middleware('module:ecommerce_process')->whereNumber('id');
        Route::get('/chat/config', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerConfig']);
        Route::get('/chat/notifications', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerNotifications']);
        Route::get('/chat/unread', [\App\Http\Controllers\Ecommerce\ChatController::class, 'unread']);
        Route::post('/chat/presence', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerPresence'])->middleware('throttle:60,1');
        Route::get('/chat', [\App\Http\Controllers\Ecommerce\ChatController::class, 'index']);
        Route::get('/chat/{uuid}/messages', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerMessages']);
        Route::get('/chat/{uuid}/products', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerProducts']);
        Route::get('/chat/{uuid}/messages/{id}/image', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerImage'])->whereNumber('id');
        Route::post('/chat/{uuid}/read', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerRead']);
        Route::post('/chat/auth', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerAuth']);
        Route::post('/chat/{uuid}/messages', [\App\Http\Controllers\Ecommerce\ChatController::class, 'ownerSend'])->middleware(['module:ecommerce_process', 'throttle:60,1']);
        Route::get('/customers', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'index']);
        Route::get('/customers/{customerKey}', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'show']);
        Route::get('/customers/{customerKey}/history', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'history']);
        Route::get('/customers/{customerKey}/orders', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'orders']);
        Route::post('/customers/{customerKey}/chat', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'chat'])->middleware(['module:ecommerce_process', 'throttle:60,1']);
        Route::put('/customers/{customerKey}/block', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'block'])->middleware('module:ecommerce_process');
        Route::post('/customers/{customerKey}/credit-contact', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'creditContact'])->middleware(['subscription', 'module:customers']);
        Route::get('/customers/{customerKey}/credit-options', [\App\Http\Controllers\Ecommerce\ShopCustomerController::class, 'creditOptions'])->middleware(['subscription', 'module:customers']);
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::get('/orders/{id}', [AdminController::class, 'order'])->whereNumber('id');
        Route::get('/orders/{id}/items/{productId}/photo', [AdminController::class, 'orderItemPhoto'])->whereNumber('id')->whereNumber('productId');
        Route::get('/report', [AdminController::class, 'report']);
    });
    Route::post('/orders/{id}/transition', [AdminController::class, 'transition'])->middleware('module:ecommerce_process')->whereNumber('id');
    Route::post('/orders/{id}/requests/{requestId}', [\App\Http\Controllers\Ecommerce\OrderRequestController::class, 'resolve'])->middleware('module:ecommerce_process')->whereNumber('id')->whereNumber('requestId');
    Route::get('/orders/{id}/requests/{requestId}/photo', [\App\Http\Controllers\Ecommerce\OrderRequestController::class, 'photo'])->middleware('module:ecommerce_orders')->whereNumber('id')->whereNumber('requestId');
    Route::post('/orders/{id}/reopen', [AdminController::class, 'reopen'])->middleware('module:ecommerce_process')->whereNumber('id');
    Route::post('/orders/{id}/payments/{action}', [AdminController::class, 'payment'])
        ->whereIn('action', ['confirm', 'reject'])->middleware('module:ecommerce_payments')->whereNumber('id');
    Route::post('/orders/{id}/payments/refund', [AdminController::class, 'payment'])
        ->defaults('action', 'refund')->middleware('module:ecommerce_refunds')->whereNumber('id');
    Route::get('/orders/{id}/proofs/{proofId}', [AdminController::class, 'proof'])->middleware('module:ecommerce_payments')->whereNumber('id')->whereNumber('proofId');
});

Route::prefix('office/ecommerce')->middleware('office.auth')->group(function () {
    Route::get('/reports', [\App\Http\Controllers\Ecommerce\ShopReportController::class, 'index']);
    Route::get('/reports/{id}', [\App\Http\Controllers\Ecommerce\ShopReportController::class, 'show'])->whereNumber('id');
    Route::put('/reports/{id}', [\App\Http\Controllers\Ecommerce\ShopReportController::class, 'review'])->whereNumber('id')->middleware('throttle:30,1');
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
