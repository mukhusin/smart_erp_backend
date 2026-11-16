<?php

use Illuminate\Support\Facades\Route;
use Modules\Appraisals\Http\Controllers\Api\AppraisalController;
use Modules\Appraisals\Http\Controllers\Api\AppraisalCycleController;
use Modules\Appraisals\Http\Controllers\Api\AppraisalSettingsController;

Route::middleware(['auth:sanctum', 'module:appraisals'])->name('appraisals.')->group(function () {
    Route::middleware('can:appraisals.manage')->group(function () {
        Route::put('appraisal-settings', [AppraisalSettingsController::class, 'update'])->name('settings.update');
        Route::post('appraisal-competencies', [AppraisalSettingsController::class, 'storeCompetency'])->name('competencies.store');
        Route::put('appraisal-competencies/{competency}', [AppraisalSettingsController::class, 'updateCompetency'])->name('competencies.update');
        Route::delete('appraisal-competencies/{competency}', [AppraisalSettingsController::class, 'destroyCompetency'])->name('competencies.destroy');
        Route::post('appraisal-cycles', [AppraisalCycleController::class, 'store'])->name('cycles.store');
        Route::put('appraisal-cycles/{cycle}', [AppraisalCycleController::class, 'update'])->name('cycles.update');
        Route::post('appraisal-cycles/{cycle}/open', [AppraisalCycleController::class, 'open'])->name('cycles.open');
        Route::post('appraisal-cycles/{cycle}/close', [AppraisalCycleController::class, 'close'])->name('cycles.close');
        Route::delete('appraisal-cycles/{cycle}', [AppraisalCycleController::class, 'destroy'])->name('cycles.destroy');
    });
    Route::get('appraisal-settings', [AppraisalSettingsController::class, 'show'])->name('settings.show');
    Route::get('appraisal-competencies', [AppraisalSettingsController::class, 'competencies'])->name('competencies.index');
    Route::get('appraisal-cycles/{cycle}', [AppraisalCycleController::class, 'show'])->name('cycles.show');
    Route::get('appraisal-cycles', [AppraisalCycleController::class, 'index'])->name('cycles.index');

    Route::get('appraisals', [AppraisalController::class, 'index'])->name('index');
    Route::get('appraisals/{appraisal}', [AppraisalController::class, 'show'])->name('show');
    Route::get('appraisals/{appraisal}/pdf', [AppraisalController::class, 'pdf'])->name('pdf');
    Route::post('appraisals/{appraisal}/self', [AppraisalController::class, 'self'])->name('self');
    Route::post('appraisals/{appraisal}/review', [AppraisalController::class, 'review'])->name('review');
    Route::post('appraisals/{appraisal}/mid-year', [AppraisalController::class, 'midYear'])->name('mid-year');
    Route::post('appraisals/{appraisal}/acknowledge', [AppraisalController::class, 'acknowledge'])->name('acknowledge');
    Route::post('appraisals/{appraisal}/withdraw', [AppraisalController::class, 'withdraw'])->name('withdraw');
    Route::post('appraisals/{appraisal}/cancel', [AppraisalController::class, 'cancel'])->name('cancel');
});
