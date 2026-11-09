<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Api\AuthController;
use Modules\Core\Http\Controllers\Api\MeController;
use Modules\Core\Http\Controllers\Api\SetPasswordController;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('auth.login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('auth.logout');
    Route::post('set-password', SetPasswordController::class)->middleware('throttle:6,1')->name('auth.set-password');
});

Route::get('me', MeController::class)->middleware('auth:sanctum')->name('me');
