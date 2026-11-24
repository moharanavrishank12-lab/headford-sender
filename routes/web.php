<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\DashboardController as D;
use App\Http\Middleware\ApiToken;
use App\Http\Middleware\DashAuth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [D::class, 'loginForm']);
Route::post('/login', [D::class, 'login']);
Route::get('/logout', [D::class, 'logout']);
Route::get('/u/{id}/{token}', [D::class, 'unsubForm'])->whereNumber('id');
Route::post('/u/{id}/{token}', [D::class, 'unsub'])->whereNumber('id')->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

Route::middleware(DashAuth::class)->group(function () {
    Route::get('/', [D::class, 'home']);
    Route::post('/account/{account}', [D::class, 'account']);
    Route::post('/accounts', [D::class, 'addAccounts']);
    Route::post('/settings', [D::class, 'settings']);
    Route::post('/reply/{reply}', [D::class, 'decide']);
});

Route::prefix('api')->middleware(ApiToken::class)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function () {
    Route::post('/leads', [ApiController::class, 'leads']);
    Route::get('/sync', [ApiController::class, 'sync']);
    Route::post('/suppressions', [ApiController::class, 'suppressions']);
    Route::get('/ping', fn () => ['ok' => true, 'time' => now()->toIso8601String()]);
});
