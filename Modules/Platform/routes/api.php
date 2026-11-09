<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\Api\CompanyController;
use Modules\Platform\Http\Controllers\Api\ModuleCatalogController;

Route::prefix('platform')->middleware(['auth:sanctum', 'platform'])->name('platform.')->group(function () {
    Route::get('modules', ModuleCatalogController::class)->name('modules.index');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::put('companies/{company}/modules', [CompanyController::class, 'updateModules'])->name('companies.modules');
    Route::post('companies/{company}/branding', [CompanyController::class, 'updateBranding'])->name('companies.branding');
    Route::post('companies/{company}/resend-invite', [CompanyController::class, 'resendInvite'])->name('companies.resend-invite');
});
