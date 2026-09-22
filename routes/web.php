<?php

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\LandingController;
use App\Models\AppRelease;
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

Route::get('/office/{path?}', function () {
    $index = public_path('office/index.html');
    abort_unless(is_file($index), 404, 'Office build not found. Run npm run build:laravel in office.');

    return response()->file($index);
})->where('path', '.*');

Route::get('/app/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 404, 'React build not found. Run npm run build:laravel in frontend.');

    return response()->file($index);
})->where('path', '.*');

Route::get('/mobile/{path?}', function () {
    $index = public_path('mobile/index.html');
    abort_unless(is_file($index), 404, 'Mobile build not found. Run npm run build:laravel in mobile.');

    return response()->file($index);
})->where('path', '.*');

Route::get('/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 404, 'React build not found. Run npm run build:laravel in frontend.');

    return response()->file($index);
})->where('path', '^(?!api(?:/|$)|app(?:/|$)|office(?:/|$)|mobile(?:/|$)).*');
