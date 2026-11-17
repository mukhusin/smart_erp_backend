<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\Api\AccountController;
use Modules\Accounting\Http\Controllers\Api\BankFeedController;
use Modules\Accounting\Http\Controllers\Api\FiscalYearController;
use Modules\Accounting\Http\Controllers\Api\FrameworkController;
use Modules\Accounting\Http\Controllers\Api\JournalController;
use Modules\Accounting\Http\Controllers\Api\MasterDataController;
use Modules\Accounting\Http\Controllers\Api\OpeningBalanceController;
use Modules\Accounting\Http\Controllers\Api\PayrollPostingController;
use Modules\Accounting\Http\Controllers\Api\ReconciliationController;
use Modules\Accounting\Http\Controllers\Api\ReportController;
use Modules\Accounting\Http\Controllers\Api\SettingsController;
use Modules\Accounting\Http\Controllers\Api\YearEndController;

Route::prefix('accounting')->middleware(['auth:sanctum', 'module:accounting', 'can:accounting.view'])->name('accounting.')->group(function () {
    Route::get('settings', [SettingsController::class, 'settings'])->name('settings.show');
    Route::get('lookups', [SettingsController::class, 'lookups'])->name('lookups');
    Route::get('framework', [FrameworkController::class, 'show'])->name('framework.show');
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
    Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->name('opening.index');
    Route::get('opening-balances/template', [OpeningBalanceController::class, 'template'])->name('opening.template');
    Route::get('opening-balances/reconciliation', [OpeningBalanceController::class, 'reconciliation'])->name('opening.reconciliation');
    Route::get('opening-balances/{opening}', [OpeningBalanceController::class, 'show'])->name('opening.show');
    Route::post('opening-balances/{opening}/withdraw', [OpeningBalanceController::class, 'withdraw'])->name('opening.withdraw');
    Route::get('year-end', [YearEndController::class, 'index'])->name('year-end.index');
    Route::get('year-end/{fiscalYear}/statements/{report}', [YearEndController::class, 'statement'])->name('year-end.statement');
    Route::post('year-end/{fiscalYear}/withdraw', [YearEndController::class, 'withdraw'])->name('year-end.withdraw');
    Route::get('reconciliations', [ReconciliationController::class, 'index'])->name('reconciliations.index');
    Route::get('reconciliations/template', [ReconciliationController::class, 'template'])->name('reconciliations.template');
    Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
    Route::post('reconciliations/{reconciliation}/withdraw', [ReconciliationController::class, 'withdraw'])->name('reconciliations.withdraw');

    Route::middleware('can:accounting.reconcile')->group(function () {
        Route::post('reconciliations', [ReconciliationController::class, 'store'])->name('reconciliations.store');
        Route::put('reconciliations/{reconciliation}', [ReconciliationController::class, 'update'])->name('reconciliations.update');
        Route::delete('reconciliations/{reconciliation}', [ReconciliationController::class, 'destroy'])->name('reconciliations.destroy');
        Route::put('reconciliations/{reconciliation}/cleared', [ReconciliationController::class, 'clear'])->name('reconciliations.clear');
        Route::post('reconciliations/{reconciliation}/statement', [ReconciliationController::class, 'import'])->name('reconciliations.import');
        Route::delete('reconciliations/{reconciliation}/statement/{line}', [ReconciliationController::class, 'destroyStatementLine'])->name('reconciliations.statement.destroy');
        Route::post('reconciliations/{reconciliation}/auto-match', [ReconciliationController::class, 'autoMatch'])->name('reconciliations.auto-match');
        Route::post('reconciliations/{reconciliation}/statement/{line}/match', [ReconciliationController::class, 'match'])->name('reconciliations.match');
        Route::post('reconciliations/{reconciliation}/statement/{line}/adjust', [ReconciliationController::class, 'adjust'])->name('reconciliations.adjust');
        Route::post('reconciliations/{reconciliation}/pull-feed', [BankFeedController::class, 'pull'])->name('reconciliations.pull-feed');
        Route::post('reconciliations/{reconciliation}/submit', [ReconciliationController::class, 'submit'])->name('reconciliations.submit');
    });
    Route::get('cash-bank/{cashBank}/feed', [BankFeedController::class, 'show'])->name('cash-bank.feed.show');
    Route::post('cash-bank/{cashBank}/feed/sync', [BankFeedController::class, 'sync'])->name('cash-bank.feed.sync');
    Route::put('cash-bank/{cashBank}/feed', [BankFeedController::class, 'update'])->middleware('can:accounting.manage')->name('cash-bank.feed.update');
    Route::post('cash-bank/{cashBank}/feed/test', [BankFeedController::class, 'test'])->middleware('can:accounting.manage')->name('cash-bank.feed.test');
    Route::post('reconciliations/{reconciliation}/reopen', [ReconciliationController::class, 'reopen'])->middleware('can:accounting.manage')->name('reconciliations.reopen');

    Route::middleware('can:accounting.opening')->group(function () {
        Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->name('opening.store');
        Route::put('opening-balances/{opening}', [OpeningBalanceController::class, 'update'])->name('opening.update');
        Route::delete('opening-balances/{opening}', [OpeningBalanceController::class, 'destroy'])->name('opening.destroy');
        Route::post('opening-balances/{opening}/rows', [OpeningBalanceController::class, 'rows'])->name('opening.rows');
        Route::post('opening-balances/{opening}/import', [OpeningBalanceController::class, 'import'])->name('opening.import');
        Route::delete('opening-balances/{opening}/rows/{line}', [OpeningBalanceController::class, 'destroyRow'])->name('opening.rows.destroy');
        Route::post('opening-balances/{opening}/submit', [OpeningBalanceController::class, 'submit'])->name('opening.submit');
    });

    Route::middleware('can:accounting.journals')->group(function () {
        Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
        Route::put('journals/{journal}', [JournalController::class, 'update'])->name('journals.update');
        Route::post('journals/{journal}/submit', [JournalController::class, 'submit'])->name('journals.submit');
        Route::delete('journals/{journal}', [JournalController::class, 'destroy'])->name('journals.destroy');
    });
    Route::post('journals/{journal}/withdraw', [JournalController::class, 'withdraw'])->name('journals.withdraw');

    Route::middleware('can:accounting.manage')->group(function () {
        Route::put('settings', [SettingsController::class, 'updateSettings'])->name('settings.update');
        Route::put('framework', [FrameworkController::class, 'update'])->name('framework.update');
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
        Route::put('year-end/settings', [YearEndController::class, 'settings'])->name('year-end.settings');
        Route::post('year-end/{fiscalYear}/close', [YearEndController::class, 'close'])->name('year-end.close');
        Route::post('year-end/{fiscalYear}/reopen', [YearEndController::class, 'reopen'])->name('year-end.reopen');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
    });
});
