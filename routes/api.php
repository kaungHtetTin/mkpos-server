<?php

use App\Http\Controllers\Api\AccessRoleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DataBackupController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PriceTypeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Office\AppReleaseController;
use App\Models\AppRelease;
use App\Http\Controllers\Office\BusinessSubscriptionController;
use App\Http\Controllers\Office\FinancialReportController;
use App\Http\Controllers\Office\OfficeAuthController;
use App\Http\Controllers\Office\PaymentMethodController;
use App\Http\Controllers\Office\PlanController;
use App\Http\Controllers\Office\TutorialController;
use App\Services\AccessService;
use App\Support\BusinessLogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('/health', fn () => ['ok' => DB::selectOne('SELECT 1') !== null, 'backend' => 'laravel', 'database' => 'mysql']);
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'business'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])
        ->middleware(['throttle:10,1', 'subscription']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/app-config', fn () => array_merge(config('mkpos'), [
        'business' => array_merge(
            request()->user('web')->business->only(['id', 'name', 'slug', 'timezone', 'currency']),
            [
                'logo_data_url' => BusinessLogo::dataUrl((string) (DB::table('settings')
                    ->where('key', BusinessLogo::SETTING_KEY)
                    ->value('value') ?? '')),
            ],
        ),
        'permissions' => app(AccessService::class)->permissions(request()->user('web')),
        'language' => DB::table('settings')->where('key', 'language')->value('value') ?: 'en',
    ]));
    Route::get('/subscription', [SubscriptionController::class, 'status']);
    Route::middleware('owner')->group(function () {
        Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
        Route::get('/subscription/payment-methods', [SubscriptionController::class, 'paymentMethods']);
        Route::get('/subscription/billing-history', [SubscriptionController::class, 'billingHistory']);
        Route::post('/subscription/requests', [SubscriptionController::class, 'requestPlan']);
    });

    Route::middleware('subscription')->group(function () {
        Route::middleware('module:products,sell,purchases')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/categories', [ProductController::class, 'categories']);
            Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
            Route::get('/products/summary', [ProductController::class, 'summary']);
            Route::get('/products/barcode/{barcode}', [ProductController::class, 'barcode']);
            Route::get('/products/{id}/photo', [ProductController::class, 'photo'])->whereNumber('id');
            Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('module:products')->group(function () {
            Route::post('/products/internal-barcode', [ProductController::class, 'internalBarcode']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{id}', [ProductController::class, 'update']);
            Route::delete('/products/{id}', [ProductController::class, 'destroy']);
            Route::post('/products/{id}/photo', [ProductController::class, 'uploadPhoto'])->whereNumber('id');
            Route::delete('/products/{id}/photo', [ProductController::class, 'deletePhoto'])->whereNumber('id');
            Route::post('/products/{id}/adjust-stock', [ProductController::class, 'adjustStock']);
            Route::get('/products/{id}/stock-movements', [ProductController::class, 'movements']);
        });

        Route::get('/price-types', [PriceTypeController::class, 'index'])->middleware('module:products,sell');
        Route::middleware('module:products')->group(function () {
            Route::post('/price-types', [PriceTypeController::class, 'store']);
            Route::put('/price-types/{priceType}', [PriceTypeController::class, 'update']);
            Route::delete('/price-types/{priceType}', [PriceTypeController::class, 'destroy']);
        });

        Route::get('/customers', [CustomerController::class, 'index'])->middleware('module:customers,sell,transactions');
        Route::middleware('module:customers')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{id}', [CustomerController::class, 'update']);
            Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
        });
        Route::middleware('module:customers,transactions')->group(function () {
            Route::get('/customers/{id}/detail', [CustomerController::class, 'detail']);
            Route::get('/customers/{id}/statement', [CustomerController::class, 'statement']);
            Route::get('/customers/{id}/sales', [CustomerController::class, 'sales']);
            Route::get('/customers/{id}/payments', [CustomerController::class, 'payments']);
            Route::post('/customers/{id}/payments', [CustomerController::class, 'storePayment']);
            Route::get('/customer-payments', [CustomerController::class, 'allPayments']);
            Route::get('/customer-payments/{id}', [CustomerController::class, 'showPayment']);
            Route::put('/customer-payments/{id}', [CustomerController::class, 'updatePayment']);
            Route::delete('/customer-payments/{id}', [CustomerController::class, 'destroyPayment']);
        });

        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('module:suppliers,purchases,transactions');
        Route::middleware('module:suppliers')->group(function () {
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
            Route::get('/suppliers/{id}/statement', [SupplierController::class, 'statement']);
            Route::post('/suppliers/{id}/payments', [SupplierController::class, 'storePayment']);
            Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
            Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
        });
        Route::middleware('module:suppliers,transactions')->group(function () {
            Route::get('/supplier-payments', [SupplierController::class, 'allPayments']);
            Route::get('/supplier-payments/{id}', [SupplierController::class, 'showPayment']);
            Route::put('/supplier-payments/{id}', [SupplierController::class, 'updatePayment']);
            Route::delete('/supplier-payments/{id}', [SupplierController::class, 'destroyPayment']);
        });

        Route::get('/sales/last/receipt', [SaleController::class, 'lastReceipt'])->middleware('module:sell,sales');
        Route::get('/sales', [SaleController::class, 'index'])->middleware('module:sales');
        Route::middleware('module:sales,customers')->group(function () {
            Route::get('/sales/{id}', [SaleController::class, 'show']);
            Route::put('/sales/{id}', [SaleController::class, 'update']);
            Route::delete('/sales/{id}', [SaleController::class, 'destroy']);
            Route::post('/sales/{id}/void', [SaleController::class, 'destroy']);
        });
        Route::middleware('module:sell,sales,customers')->group(function () {
            Route::get('/sales/{id}/receipt', [SaleController::class, 'receipt']);
            Route::post('/sales/{id}/print', [SaleController::class, 'print']);
        });
        Route::post('/sales/offline-sync', [SaleController::class, 'offlineSync'])->middleware('module:sell');
        Route::post('/sales', [SaleController::class, 'store'])->middleware('module:sell');

        Route::middleware('module:purchases,transactions')->group(function () {
            Route::get('/purchases', [PurchaseController::class, 'index']);
            Route::post('/purchases', [PurchaseController::class, 'store']);
            Route::get('/purchases/{id}', [PurchaseController::class, 'show']);
            Route::put('/purchases/{id}', [PurchaseController::class, 'update']);
            Route::delete('/purchases/{id}', [PurchaseController::class, 'destroy']);
            Route::post('/purchases/{id}/void', [PurchaseController::class, 'destroy']);
        });

        Route::middleware('module:expenses,transactions')->group(function () {
            Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
            Route::get('/expenses', [ExpenseController::class, 'index']);
            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::get('/expenses/{id}', [ExpenseController::class, 'show']);
            Route::put('/expenses/{id}', [ExpenseController::class, 'update']);
            Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy']);
            Route::post('/expenses/{id}/void', [ExpenseController::class, 'destroy']);
        });

        Route::middleware('module:reports')->group(function () {
            Route::get('/reports/summary', [ReportController::class, 'summary']);
            Route::get('/reports/today-summary', [ReportController::class, 'today']);
            Route::get('/reports/summary.csv', [ReportController::class, 'csv']);
        });

        Route::middleware('owner')->group(function () {
            Route::get('/roles', [AccessRoleController::class, 'index']);
            Route::get('/roles/{id}', [AccessRoleController::class, 'show']);
            Route::post('/roles', [AccessRoleController::class, 'store']);
            Route::put('/roles/{id}', [AccessRoleController::class, 'update']);
            Route::delete('/roles/{id}', [AccessRoleController::class, 'destroy']);
            Route::get('/staff', [StaffController::class, 'index']);
            Route::get('/staff/{id}', [StaffController::class, 'show']);
            Route::post('/staff', [StaffController::class, 'store']);
            Route::put('/staff/{id}', [StaffController::class, 'update']);
            Route::put('/staff/{id}/password', [StaffController::class, 'resetPassword'])->middleware('throttle:10,1');
            Route::delete('/staff/{id}', [StaffController::class, 'destroy']);

            Route::post('/settings/logo', [SettingsController::class, 'uploadLogo']);
            Route::delete('/settings/logo', [SettingsController::class, 'deleteLogo']);
            Route::get('/data/status', [DataBackupController::class, 'status']);
            Route::post('/data/reset', [DataBackupController::class, 'reset'])->middleware('throttle:3,1');
        });
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::get('/settings/printers', [SettingsController::class, 'printers']);
        Route::post('/settings/receipt-preview', [SettingsController::class, 'receiptPreview']);
        Route::post('/settings/test-print', [SettingsController::class, 'testPrint']);
        Route::get('/data/export', [DataBackupController::class, 'export'])
            ->middleware(['subscription.capability:data_export', 'owner']);
        Route::post('/data/restore-file', [DataBackupController::class, 'restore'])
            ->middleware(['subscription.capability:data_restore', 'owner']);
        Route::get('/settings', [SettingsController::class, 'index']);
    });
});

Route::post('/office/auth/login', [OfficeAuthController::class, 'login'])->middleware('throttle:10,1');
Route::middleware('office.auth')->prefix('office')->group(function () {
    Route::get('/tutorials', [TutorialController::class, 'index']);
    Route::post('/tutorials', [TutorialController::class, 'store']);
    Route::put('/tutorials/{tutorial}', [TutorialController::class, 'update'])->whereNumber('tutorial');
    Route::delete('/tutorials/{tutorial}', [TutorialController::class, 'destroy'])->whereNumber('tutorial');
    Route::get('/tutorials/{tutorial}/thumbnail', [TutorialController::class, 'thumbnail'])->whereNumber('tutorial');
    Route::get('/auth/me', [OfficeAuthController::class, 'me']);
    Route::put('/auth/profile', [OfficeAuthController::class, 'updateProfile'])->middleware('throttle:10,1');
    Route::post('/auth/logout', [OfficeAuthController::class, 'logout']);
    Route::get('/app-releases', [AppReleaseController::class, 'index']);
    Route::put('/app-releases/{platform}', [AppReleaseController::class, 'store'])
        ->whereIn('platform', AppRelease::PLATFORMS)
        ->middleware('throttle:12,1');
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{id}', [PlanController::class, 'update']);
    Route::delete('/plans/{id}', [PlanController::class, 'destroy']);
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update']);
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);
    Route::get('/businesses', [BusinessSubscriptionController::class, 'index']);
    Route::get('/businesses/{businessId}', [BusinessSubscriptionController::class, 'show']);
    Route::put('/businesses/{businessId}/owner-password', [BusinessSubscriptionController::class, 'resetOwnerPassword'])->middleware('throttle:10,1');
    Route::get('/subscription-requests', [BusinessSubscriptionController::class, 'requests']);
    Route::get('/financial-report', [FinancialReportController::class, 'index']);
    Route::delete('/financial-records/{paymentId}', [FinancialReportController::class, 'destroy'])->whereNumber('paymentId');
    Route::put('/businesses/{businessId}/subscription', [BusinessSubscriptionController::class, 'assign']);
    Route::post('/businesses/{businessId}/subscription/renew', [BusinessSubscriptionController::class, 'renew']);
    Route::post('/businesses/{businessId}/subscription/trial/extend', [BusinessSubscriptionController::class, 'extendTrial']);
    Route::patch('/businesses/{businessId}/subscription/status', [BusinessSubscriptionController::class, 'setStatus']);
    Route::delete('/businesses/{businessId}/subscription', [BusinessSubscriptionController::class, 'cancel']);
    Route::post('/subscription-requests/{requestId}/approve', [BusinessSubscriptionController::class, 'approve']);
    Route::post('/subscription-requests/{requestId}/reject', [BusinessSubscriptionController::class, 'reject']);
    Route::get('/subscription-requests/{requestId}/payment-screenshot', [BusinessSubscriptionController::class, 'paymentScreenshot']);
});
