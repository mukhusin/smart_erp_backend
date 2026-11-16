<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\Api\AccountController;
use Modules\Accounting\Http\Controllers\Api\FiscalYearController;
use Modules\Accounting\Http\Controllers\Api\JournalController;
use Modules\Accounting\Http\Controllers\Api\MasterDataController;
use Modules\Accounting\Http\Controllers\Api\PayrollPostingController;
use Modules\Accounting\Http\Controllers\Api\ReportController;
use Modules\Accounting\Http\Controllers\Api\SettingsController;

Route::prefix('accounting')->middleware(['auth:sanctum', 'module:accounting', 'can:accounting.view'])->name('accounting.')->group(function () {
    Route::get('settings', [SettingsController::class, 'settings'])->name('settings.show');
    Route::get('lookups', [SettingsController::class, 'lookups'])->name('lookups');
    Route::get('accounts', [AccountController::class, 'accounts'])->name('accounts.index');
    Route::get('accounts/template', [AccountController::class, 'accountsTemplate'])->name('accounts.template');
    Route::get('fund-sources', [MasterDataController::class, 'fundSources'])->name('fund-sources.index');
    Route::get('cash-bank', [MasterDataController::class, 'cashBank'])->name('cash-bank.index');
    Route::get('fiscal-years', [FiscalYearController::class, 'fiscalYears'])->name('fiscal-years.index');
    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
    Route::get('payroll/{sheet}', [PayrollPostingController::class, 'show'])->name('payroll.show');
    Route::post('payroll/{sheet}/post', [PayrollPostingController::class, 'post'])->name('payroll.post');
    Route::post('payroll/{sheet}/pay', [PayrollPostingController::class, 'pay'])->name('payroll.pay');
    Route::get('payroll-posting', [PayrollPostingController::class, 'settings'])->name('payroll-posting.show');
    Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('reports/ledger', [ReportController::class, 'ledger'])->name('reports.ledger');

    Route::middleware('can:accounting.journals')->group(function () {
        Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
        Route::put('journals/{journal}', [JournalController::class, 'update'])->name('journals.update');
        Route::post('journals/{journal}/submit', [JournalController::class, 'submit'])->name('journals.submit');
        Route::delete('journals/{journal}', [JournalController::class, 'destroy'])->name('journals.destroy');
    });
    Route::post('journals/{journal}/withdraw', [JournalController::class, 'withdraw'])->name('journals.withdraw');

    Route::middleware('can:accounting.manage')->group(function () {
        Route::put('settings', [SettingsController::class, 'updateSettings'])->name('settings.update');
        Route::put('payroll-posting', [PayrollPostingController::class, 'updateSettings'])->name('payroll-posting.update');
        Route::post('accounts', [AccountController::class, 'storeAccount'])->name('accounts.store');
        Route::post('accounts/import', [AccountController::class, 'importAccounts'])->name('accounts.import');
        Route::put('accounts/{account}', [AccountController::class, 'updateAccount'])->name('accounts.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroyAccount'])->name('accounts.destroy');
        Route::post('fund-sources', [MasterDataController::class, 'storeFundSource'])->name('fund-sources.store');
        Route::put('fund-sources/{fundSource}', [MasterDataController::class, 'updateFundSource'])->name('fund-sources.update');
        Route::post('cash-bank', [MasterDataController::class, 'storeCashBank'])->name('cash-bank.store');
        Route::put('cash-bank/{cashBank}', [MasterDataController::class, 'updateCashBank'])->name('cash-bank.update');
        Route::post('fiscal-years', [FiscalYearController::class, 'storeFiscalYear'])->name('fiscal-years.store');
        Route::post('periods/{period}/close', [FiscalYearController::class, 'closePeriod'])->name('periods.close');
        Route::post('periods/{period}/reopen', [FiscalYearController::class, 'reopenPeriod'])->name('periods.reopen');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
    });
});
