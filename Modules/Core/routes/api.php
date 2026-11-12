<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Api\AuthController;
use Modules\Core\Http\Controllers\Api\ForgotPasswordController;
use Modules\Core\Http\Controllers\Api\LoginBrandingController;
use Modules\Core\Http\Controllers\Api\MeController;
use Modules\Core\Http\Controllers\Api\NotificationController;
use Modules\Core\Http\Controllers\Api\SetPasswordController;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('auth.login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('auth.logout');
    Route::post('set-password', SetPasswordController::class)->middleware('throttle:6,1')->name('auth.set-password');
    Route::post('forgot-password', ForgotPasswordController::class)->middleware('throttle:5,1')->name('auth.forgot-password');
    Route::get('login-branding', LoginBrandingController::class)->middleware('throttle:60,1')->name('auth.login-branding');
});

Route::get('me', MeController::class)->middleware('auth:sanctum')->name('me');
Route::get('me/notifications', [NotificationController::class, 'index'])->middleware('auth:sanctum')->name('me.notifications');
Route::post('me/notifications/read', [NotificationController::class, 'read'])->middleware('auth:sanctum')->name('me.notifications.read');
