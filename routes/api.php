<?php

use App\Http\Controllers\Api\CurrentUserApiController;
use App\Http\Controllers\Api\PublicApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public JSON API
|--------------------------------------------------------------------------
| Mirrors what the public site shows, so only approved, open listings appear.
*/

Route::prefix('public')->name('api.public.')->group(function () {
    Route::get('/scholarships', [PublicApiController::class, 'scholarships'])->name('scholarships');
    Route::get('/scholarships/{id}', [PublicApiController::class, 'scholarship'])
        ->whereNumber('id')
        ->name('scholarship');
    Route::get('/stats', [PublicApiController::class, 'stats'])->name('stats');
});

/*
|--------------------------------------------------------------------------
| Session-authenticated endpoints
|--------------------------------------------------------------------------
| Used by the dashboard shell (notification bell, current-user chip), so they
| ride the web session rather than a token guard.
*/

Route::middleware('auth:web')->group(function () {
    Route::get('/me', [CurrentUserApiController::class, 'me'])->name('api.me');
    Route::get('/me/notifications', [CurrentUserApiController::class, 'notifications'])->name('api.me.notifications');
});
