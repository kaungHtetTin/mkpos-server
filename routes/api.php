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
use App\Http\Controllers\Office\BusinessSubscriptionController;
use App\Http\Controllers\Office\FinancialReportController;
use App\Http\Controllers\Office\OfficeAuthController;
use App\Http\Controllers\Office\PaymentMethodController;
use App\Http\Controllers\Office\PlanController;
use App\Services\AccessService;
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
    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1');
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/app-config', fn () => array_merge(config('mkpos'), [
        'business' => request()->user('web')->business->only(['id', 'name', 'slug', 'timezone', 'currency']),
        'permissions' => app(AccessService::class)->permissions(request()->user('web')),
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
            Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('module:products')->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{id}', [ProductController::class, 'update']);
            Route::delete('/products/{id}', [ProductController::class, 'destroy']);
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
            Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
            Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
        });

        Route::middleware('module:sell,transactions')->group(function () {
            Route::get('/sales/last/receipt', [SaleController::class, 'lastReceipt']);
            Route::get('/sales', [SaleController::class, 'index']);
            Route::get('/sales/{id}', [SaleController::class, 'show']);
            Route::put('/sales/{id}', [SaleController::class, 'update']);
            Route::delete('/sales/{id}', [SaleController::class, 'destroy']);
            Route::post('/sales/{id}/void', [SaleController::class, 'destroy']);
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

            Route::put('/settings', [SettingsController::class, 'update']);
            Route::get('/settings/printers', [SettingsController::class, 'printers']);
            Route::post('/settings/receipt-preview', [SettingsController::class, 'receiptPreview']);
            Route::post('/settings/test-print', [SettingsController::class, 'testPrint']);
            Route::get('/data/status', [DataBackupController::class, 'status']);
            Route::get('/data/export', [DataBackupController::class, 'export']);
            Route::post('/data/restore-file', [DataBackupController::class, 'restore']);
        });
        Route::get('/settings', [SettingsController::class, 'index'])
            ->middleware('module:sell,products,purchases,suppliers,customers,expenses,transactions,reports');
    });
});

Route::post('/office/auth/login', [OfficeAuthController::class, 'login'])->middleware('throttle:10,1');
Route::middleware('office.auth')->prefix('office')->group(function () {
    Route::get('/auth/me', [OfficeAuthController::class, 'me']);
    Route::put('/auth/profile', [OfficeAuthController::class, 'updateProfile'])->middleware('throttle:10,1');
    Route::post('/auth/logout', [OfficeAuthController::class, 'logout']);
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
    Route::put('/businesses/{businessId}/subscription', [BusinessSubscriptionController::class, 'assign']);
    Route::post('/businesses/{businessId}/subscription/renew', [BusinessSubscriptionController::class, 'renew']);
    Route::delete('/businesses/{businessId}/subscription', [BusinessSubscriptionController::class, 'cancel']);
    Route::post('/subscription-requests/{requestId}/approve', [BusinessSubscriptionController::class, 'approve']);
    Route::post('/subscription-requests/{requestId}/reject', [BusinessSubscriptionController::class, 'reject']);
    Route::get('/subscription-requests/{requestId}/payment-screenshot', [BusinessSubscriptionController::class, 'paymentScreenshot']);
});
