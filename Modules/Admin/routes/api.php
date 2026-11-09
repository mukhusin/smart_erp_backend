<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\Api\BranchController;
use Modules\Admin\Http\Controllers\Api\HandoverController;
use Modules\Admin\Http\Controllers\Api\LoginSlideController;
use Modules\Admin\Http\Controllers\Api\ModuleLockController;
use Modules\Admin\Http\Controllers\Api\PermissionController;
use Modules\Admin\Http\Controllers\Api\RoleController;
use Modules\Admin\Http\Controllers\Api\UserController;

// Company administration. Every route: logged in, company's admin module available, and the listed permission.
Route::prefix('admin')->middleware(['auth:sanctum', 'module:admin'])->name('admin.')->group(function () {
    Route::get('permissions', PermissionController::class)->middleware('can:admin.roles.manage')->name('permissions');
    Route::get('branches', BranchController::class)->middleware('can:admin.users.view')->name('branches');

    Route::middleware('can:admin.users.view')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::get('users/{user}/access-history', [UserController::class, 'history'])->name('users.history');
    });
    Route::middleware('can:admin.roles.assign')->group(function () {
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles');
        Route::put('users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('users.permissions');
    });
    Route::middleware('can:admin.users.manage')->group(function () {
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
    });

    // Anyone who manages users needs the role list (to assign roles).
    Route::get('roles', [RoleController::class, 'index'])->middleware('can:admin.users.view')->name('roles.index');
    Route::middleware('can:admin.roles.manage')->group(function () {
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::middleware('can:admin.handovers.manage')->group(function () {
        Route::get('handovers', [HandoverController::class, 'index'])->name('handovers.index');
        Route::post('handovers', [HandoverController::class, 'store'])->name('handovers.store');
        Route::post('handovers/{handover}/revoke', [HandoverController::class, 'revoke'])->name('handovers.revoke');
    });

    Route::middleware('can:admin.module-locks.manage')->group(function () {
        Route::get('module-locks', [ModuleLockController::class, 'index'])->name('module-locks.index');
        Route::post('module-locks', [ModuleLockController::class, 'store'])->name('module-locks.store');
        Route::delete('module-locks/{moduleLock}', [ModuleLockController::class, 'destroy'])->name('module-locks.destroy');
    });

    Route::middleware('can:admin.settings.manage')->group(function () {
        Route::get('login-slides', [LoginSlideController::class, 'index'])->name('login-slides.index');
        Route::post('login-slides', [LoginSlideController::class, 'store'])->name('login-slides.store');
        Route::post('login-slides/{loginSlide}', [LoginSlideController::class, 'update'])->name('login-slides.update');
        Route::delete('login-slides/{loginSlide}', [LoginSlideController::class, 'destroy'])->name('login-slides.destroy');
    });
});
