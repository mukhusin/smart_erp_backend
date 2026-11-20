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

Route::get('reports/executive', \Modules\Reporting\Http\Controllers\Api\ExecutiveDashboardController::class)->middleware(['auth:sanctum', 'can:reports.executive'])->name('reports.executive');
Route::middleware(['auth:sanctum', 'can:reports.executive'])->prefix('reports/executive')->name('reports.executive.')->group(function () {
    Route::get('payments', [\Modules\Reporting\Http\Controllers\Api\PaymentTrackerController::class, 'index'])->name('payments');
    Route::get('approvers', [\Modules\Reporting\Http\Controllers\Api\PaymentTrackerController::class, 'approvers'])->name('approvers');
});
