<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\Api\FinanceReportsController;
use Modules\Reporting\Support\FinanceReports;

Route::prefix('reports/finance')->middleware(['auth:sanctum'])->name('reports.finance.')->group(function () {
    Route::get('/', [FinanceReportsController::class, 'index'])->name('index');
    Route::get('{report}', [FinanceReportsController::class, 'show'])->whereIn('report', array_keys(FinanceReports::REPORTS))->name('show');
    Route::get('{report}/csv', [FinanceReportsController::class, 'csv'])->whereIn('report', array_keys(FinanceReports::REPORTS))->name('csv');
    Route::get('{report}/pdf', [FinanceReportsController::class, 'pdf'])->whereIn('report', array_keys(FinanceReports::REPORTS))->name('pdf');
});
