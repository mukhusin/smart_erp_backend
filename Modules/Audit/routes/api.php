<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\Api\AuditController;

Route::prefix('audit')->middleware(['auth:sanctum', 'module:audit'])->name('audit.')->group(function () {
    Route::get('dashboard', [AuditController::class, 'dashboard'])->name('dashboard');
    Route::get('checks/{key}', [AuditController::class, 'check'])->name('checks.show');
    Route::get('payroll', [AuditController::class, 'payroll'])->name('payroll');
    Route::get('users', [AuditController::class, 'users'])->name('users');
    Route::get('queries', [AuditController::class, 'queries'])->name('queries.index');
    Route::post('queries', [AuditController::class, 'storeQuery'])->name('queries.store');
    Route::post('queries/{query}/respond', [AuditController::class, 'respond'])->name('queries.respond');
    Route::post('queries/{query}/close', [AuditController::class, 'close'])->name('queries.close');
    Route::get('engagements', [AuditController::class, 'engagements'])->name('engagements.index');
    Route::post('engagements', [AuditController::class, 'storeEngagement'])->name('engagements.store');
    Route::post('engagements/{engagement}/withdraw', [AuditController::class, 'withdrawEngagement'])->name('engagements.withdraw');
    Route::post('engagements/{engagement}/end', [AuditController::class, 'endEngagement'])->name('engagements.end');
});
