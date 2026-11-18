<?php

use Illuminate\Support\Facades\Route;
use Modules\Assets\Http\Controllers\Api\AssetActivityController;
use Modules\Assets\Http\Controllers\Api\AssetController;
use Modules\Assets\Http\Controllers\Api\DepreciationController;
use Modules\Assets\Http\Controllers\Api\MyAssetsController;

Route::middleware(['auth:sanctum', 'module:assets'])->group(function () {
    Route::prefix('self/assets')->name('self.assets.')->group(function () {
        Route::get('/', [MyAssetsController::class, 'index'])->name('index');
        Route::post('confirm', [MyAssetsController::class, 'confirm'])->name('confirm');
        Route::post('{asset}/report', [MyAssetsController::class, 'report'])->name('report');
    });

    Route::prefix('assets')->name('assets.')->group(function () {
        Route::get('dashboard', [AssetController::class, 'dashboard'])->name('dashboard');
        Route::get('lookups', [AssetController::class, 'lookups'])->name('lookups');
        Route::get('register', [AssetController::class, 'index'])->name('index');
        Route::get('lookup', [AssetController::class, 'lookup'])->name('lookup');
        Route::get('register/template', [AssetController::class, 'template'])->name('template');
        Route::get('register/{asset}', [AssetController::class, 'show'])->name('show');
        Route::post('register/{asset}/withdraw', [AssetController::class, 'withdraw'])->name('withdraw');
        Route::post('changes/{change}/withdraw', [AssetActivityController::class, 'withdrawChange'])->name('changes.withdraw');
        Route::post('transfers/{transfer}/withdraw', [AssetActivityController::class, 'withdrawTransfer'])->name('transfers.withdraw');
        Route::post('disposals/{disposal}/withdraw', [AssetActivityController::class, 'withdrawDisposal'])->name('disposals.withdraw');
        Route::get('disposals/{disposal}/minutes', [AssetActivityController::class, 'minutes'])->name('disposals.minutes');
        Route::get('reports', [AssetActivityController::class, 'reports'])->name('reports.index');
        Route::get('reports/{report}/photo', [AssetActivityController::class, 'photo'])->name('reports.photo');
        Route::get('depreciation', [DepreciationController::class, 'index'])->name('depreciation.index');
        Route::get('settings', [DepreciationController::class, 'settings'])->name('settings.show');

        Route::middleware('can:assets.manage')->group(function () {
            Route::post('register', [AssetController::class, 'store'])->name('store');
            Route::post('register/import', [AssetController::class, 'import'])->name('import');
            Route::post('register/submit-drafts', [AssetController::class, 'submitDrafts'])->name('submit-drafts');
            Route::put('register/{asset}', [AssetController::class, 'update'])->name('update');
            Route::delete('register/{asset}', [AssetController::class, 'destroy'])->name('destroy');
            Route::post('register/{asset}/submit', [AssetController::class, 'submit'])->name('submit');
            Route::post('register/{asset}/assign', [AssetController::class, 'assign'])->name('assign');
            Route::post('register/{asset}/return', [AssetController::class, 'returnAsset'])->name('return');
            Route::post('register/{asset}/status', [AssetController::class, 'status'])->name('status');
            Route::post('register/{asset}/changes', [AssetActivityController::class, 'change'])->name('changes.store');
            Route::post('register/{asset}/transfers', [AssetActivityController::class, 'transfer'])->name('transfers.store');
            Route::post('register/{asset}/disposals', [AssetActivityController::class, 'dispose'])->name('disposals.store');
            Route::post('transfers/{transfer}/return', [AssetActivityController::class, 'returnTransfer'])->name('transfers.return');
            Route::post('reports/{report}/handle', [AssetActivityController::class, 'handleReport'])->name('reports.handle');
            Route::post('depreciation/run', [DepreciationController::class, 'run'])->name('depreciation.run');
            Route::put('settings', [DepreciationController::class, 'updateSettings'])->name('settings.update');
        });
    });
});
