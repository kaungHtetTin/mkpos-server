<?php

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\LandingController;
use App\Models\AppRelease;
use App\Support\ClientApps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/documentation', [LandingController::class, 'documentation'])->name('documentation.index');
Route::get('/documentation/{topic}', [LandingController::class, 'documentation'])->name('documentation.show');
Route::get('/plans', [LandingController::class, 'plans'])->name('plans.index');
Route::get('/tutorials', [LandingController::class, 'tutorials'])->name('tutorials.index');
Route::get('/tutorials/{tutorial}/thumbnail', [LandingController::class, 'tutorialThumbnail'])->whereNumber('tutorial')->name('tutorials.thumbnail');
Route::get('/downloads/{platform}', [DownloadController::class, 'show'])
    ->whereIn('platform', AppRelease::PLATFORMS)
    ->name('downloads.show');

foreach (['app', 'mobile', 'office'] as $client) {
    Route::get('/'.$client.'/{path?}', function (Request $request, string $path = '') use ($client) {
        return redirect()->away(ClientApps::legacyUrl($client, $path, $request->getQueryString()), 302);
    })->where('path', '.*');
}

Route::get('/shop/{path?}', function () {
    $index = public_path('shop/index.html');
    abort_unless(is_file($index), 404, 'Customer shop build not found. Run npm run build:laravel in shop.');

    return response()->file($index);
})->where('path', '.*');

Route::get('/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 404, 'React build not found. Run npm run build:laravel in frontend.');

    return response()->file($index);
})->where('path', '^(?!api(?:/|$)|app(?:/|$)|office(?:/|$)|mobile(?:/|$)|shop(?:/|$)).*');
