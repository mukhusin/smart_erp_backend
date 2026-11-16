<?php

use Illuminate\Support\Facades\Route;
use Modules\Budgeting\Http\Controllers\Api\BudgetController;
use Modules\Budgeting\Http\Controllers\Api\BudgetReportController;
use Modules\Budgeting\Http\Controllers\Api\DepartmentBudgetController;
use Modules\Budgeting\Http\Controllers\Api\TransferController;

Route::prefix('budgeting')->middleware(['auth:sanctum', 'module:budgeting'])->name('budgeting.')->group(function () {
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::get('budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
    Route::put('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
    Route::post('budgets/{budget}/open', [BudgetController::class, 'open'])->name('budgets.open');
    Route::post('budgets/{budget}/submit', [BudgetController::class, 'submit'])->name('budgets.submit');
    Route::post('budgets/{budget}/releases', [BudgetController::class, 'release'])->name('budgets.release');
    Route::post('budgets/{budget}/close', [BudgetController::class, 'close'])->name('budgets.close');

    Route::get('budgets/{budget}/departments/{department}', [DepartmentBudgetController::class, 'show'])->name('departments.show');
    Route::post('budgets/{budget}/departments/{department}/items', [DepartmentBudgetController::class, 'storeItem'])->name('items.store');
    Route::put('budgets/{budget}/departments/{department}/items/{item}', [DepartmentBudgetController::class, 'updateItem'])->name('items.update');
    Route::delete('budgets/{budget}/departments/{department}/items/{item}', [DepartmentBudgetController::class, 'destroyItem'])->name('items.destroy');
    Route::put('budgets/{budget}/departments/{department}/ceiling', [DepartmentBudgetController::class, 'ceiling'])->name('departments.ceiling');
    Route::post('budgets/{budget}/departments/{department}/{action}', [DepartmentBudgetController::class, 'action'])->whereIn('action', ['submit', 'withdraw', 'reopen'])->name('departments.action');

    Route::get('budgets/{budget}/transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::post('budgets/{budget}/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::post('budgets/{budget}/transfers/{transfer}/withdraw', [TransferController::class, 'withdraw'])->name('transfers.withdraw');

    Route::get('budgets/{budget}/vs-actual', [BudgetReportController::class, 'vsActual'])->name('vs-actual');
    Route::get('items', [BudgetReportController::class, 'items'])->name('items.index');
    Route::get('items/{item}', [BudgetReportController::class, 'item'])->name('items.show');
});
