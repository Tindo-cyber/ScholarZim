<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\DocsController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Version 1
|--------------------------------------------------------------------------
| Everything is under /api/v1 so a later shape change can ship alongside this
| one rather than breaking whoever is already reading it. The unversioned paths
| the dashboard shell was written against are kept below as aliases.
|
| The catalogue is public and rate-limited; the /me endpoints accept either a
| Sanctum bearer token (an integration) or the ordinary web session (the
| dashboard's own fetches), which is what the two guards on that group mean.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/openapi.json', [DocsController::class, 'spec'])->name('openapi');

    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/scholarships', [CatalogController::class, 'index'])->name('scholarships.index');
        Route::get('/scholarships/{id}', [CatalogController::class, 'show'])
            ->whereNumber('id')
            ->name('scholarships.show');
        Route::get('/stats', [CatalogController::class, 'stats'])->name('stats');
        Route::get('/facets', [CatalogController::class, 'facets'])->name('facets');
    });

    Route::middleware(['auth:sanctum,web', 'throttle:120,1'])->group(function () {
        Route::get('/me', [MeController::class, 'show'])->name('me');
        Route::get('/me/notifications', [MeController::class, 'notifications'])->name('me.notifications');
        Route::get('/me/applications', [MeController::class, 'applications'])->name('me.applications');
        Route::get('/me/recommendations', [MeController::class, 'recommendations'])->name('me.recommendations');
    });
});

/*
|--------------------------------------------------------------------------
| Unversioned aliases (deprecated)
|--------------------------------------------------------------------------
| The paths that existed before versioning. They point at the same v1
| controllers so nothing already calling them breaks; new clients should use
| /api/v1.
*/

Route::prefix('public')->name('api.public.')->group(function () {
    Route::get('/scholarships', [CatalogController::class, 'index'])->name('scholarships');
    Route::get('/scholarships/{id}', [CatalogController::class, 'show'])
        ->whereNumber('id')
        ->name('scholarship');
    Route::get('/stats', [CatalogController::class, 'stats'])->name('stats');
});

Route::middleware('auth:web')->group(function () {
    Route::get('/me', [MeController::class, 'show'])->name('api.me');
    Route::get('/me/notifications', [MeController::class, 'notifications'])->name('api.me.notifications');
});
