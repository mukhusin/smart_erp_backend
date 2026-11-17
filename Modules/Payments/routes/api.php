<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\Api\ImprestRetirementController;
use Modules\Payments\Http\Controllers\Api\PaymentListController;
use Modules\Payments\Http\Controllers\Api\PaymentRequestController;
use Modules\Payments\Http\Controllers\Api\PaymentSetupController;
use Modules\Payments\Http\Controllers\Api\PaymentVoucherController;
use Modules\Payments\Http\Controllers\Api\PettyCashController;

Route::prefix('payments')->middleware(['auth:sanctum', 'module:payments'])->name('payments.')->group(function () {
    Route::get('settings', [PaymentSetupController::class, 'settings'])->name('settings.show');
    Route::put('settings', [PaymentSetupController::class, 'updateSettings'])->name('settings.update');
    Route::get('lookups', [PaymentSetupController::class, 'lookups'])->name('lookups');
    Route::get('staff', [PaymentSetupController::class, 'staff'])->name('staff');
    Route::get('budget-lines', [PaymentSetupController::class, 'budgetLines'])->name('budget-lines');

    Route::get('requests', [PaymentRequestController::class, 'index'])->name('requests.index');
    Route::post('requests', [PaymentRequestController::class, 'store'])->name('requests.store');
    Route::get('requests/{paymentRequest}', [PaymentRequestController::class, 'show'])->name('requests.show');
    Route::put('requests/{paymentRequest}', [PaymentRequestController::class, 'update'])->name('requests.update');
    Route::delete('requests/{paymentRequest}', [PaymentRequestController::class, 'destroy'])->name('requests.destroy');
    Route::post('requests/{paymentRequest}/submit', [PaymentRequestController::class, 'submit'])->name('requests.submit');
    Route::post('requests/{paymentRequest}/withdraw', [PaymentRequestController::class, 'withdraw'])->name('requests.withdraw');
    Route::post('requests/{paymentRequest}/cancel', [PaymentRequestController::class, 'cancel'])->name('requests.cancel');
    Route::post('requests/{paymentRequest}/attachments', [PaymentRequestController::class, 'attach'])->name('requests.attach');
    Route::get('requests/{paymentRequest}/attachments/{attachment}', [PaymentRequestController::class, 'file'])->name('requests.file');
    Route::delete('requests/{paymentRequest}/attachments/{attachment}', [PaymentRequestController::class, 'detach'])->name('requests.detach');
    Route::post('requests/{paymentRequest}/voucher', [PaymentVoucherController::class, 'store'])->name('vouchers.store');
    Route::post('requests/{paymentRequest}/recover', [ImprestRetirementController::class, 'recover'])->name('requests.recover');
    Route::post('recoveries/{recovery}/cancel', [ImprestRetirementController::class, 'cancelRecovery'])->name('recoveries.cancel');
    Route::post('requests/{paymentRequest}/retirements', [ImprestRetirementController::class, 'store'])->name('retirements.store');

    Route::get('retirements/{retirement}', [ImprestRetirementController::class, 'show'])->name('retirements.show');
    Route::put('retirements/{retirement}', [ImprestRetirementController::class, 'update'])->name('retirements.update');
    Route::delete('retirements/{retirement}', [ImprestRetirementController::class, 'destroy'])->name('retirements.destroy');
    Route::post('retirements/{retirement}/submit', [ImprestRetirementController::class, 'submit'])->name('retirements.submit');
    Route::post('retirements/{retirement}/withdraw', [ImprestRetirementController::class, 'withdraw'])->name('retirements.withdraw');
    Route::post('retirements/{retirement}/attachments', [ImprestRetirementController::class, 'attach'])->name('retirements.attach');
    Route::get('retirements/{retirement}/attachments/{attachment}', [ImprestRetirementController::class, 'file'])->name('retirements.file');
    Route::delete('retirements/{retirement}/attachments/{attachment}', [ImprestRetirementController::class, 'detach'])->name('retirements.detach');

    Route::get('petty-cash', [PettyCashController::class, 'index'])->name('petty-cash.index');
    Route::get('petty-cash/users', [PettyCashController::class, 'users'])->name('petty-cash.users');
    Route::get('petty-cash/budget-lines', [PettyCashController::class, 'budgetLines'])->name('petty-cash.budget-lines');
    Route::post('petty-cash', [PettyCashController::class, 'storeFloat'])->name('petty-cash.store');
    Route::get('petty-cash/{float}', [PettyCashController::class, 'show'])->name('petty-cash.show');
    Route::put('petty-cash/{float}', [PettyCashController::class, 'updateFloat'])->name('petty-cash.update');
    Route::post('petty-cash/{float}/vouchers', [PettyCashController::class, 'storeVoucher'])->name('petty-cash.vouchers.store');
    Route::post('petty-cash/{float}/top-up', [PettyCashController::class, 'replenish'])->name('petty-cash.top-up');
    Route::get('petty-cash-vouchers/{voucher}/receipt', [PettyCashController::class, 'receipt'])->name('petty-cash.vouchers.receipt');
    Route::post('petty-cash-vouchers/{voucher}/withdraw', [PettyCashController::class, 'withdrawVoucher'])->name('petty-cash.vouchers.withdraw');
    Route::post('petty-cash-top-ups/{replenishment}/withdraw', [PettyCashController::class, 'withdrawReplenishment'])->name('petty-cash.top-ups.withdraw');

    Route::get('vouchers', [PaymentVoucherController::class, 'index'])->name('vouchers.index');
    Route::get('vouchers/{voucher}', [PaymentVoucherController::class, 'show'])->name('vouchers.show');
    Route::post('vouchers/{voucher}/withdraw', [PaymentVoucherController::class, 'withdraw'])->name('vouchers.withdraw');
    Route::post('vouchers/{voucher}/void', [PaymentVoucherController::class, 'void'])->name('vouchers.void');

    Route::get('lists', [PaymentListController::class, 'index'])->name('lists.index');
    Route::post('lists', [PaymentListController::class, 'store'])->name('lists.store');
    Route::get('lists/{list}', [PaymentListController::class, 'show'])->name('lists.show');
    Route::post('lists/{list}/withdraw', [PaymentListController::class, 'withdraw'])->name('lists.withdraw');
    Route::post('lists/{list}/items/{item}/fail', [PaymentListController::class, 'fail'])->name('lists.fail');
    Route::put('lists/{list}/references', [PaymentListController::class, 'references'])->name('lists.references');
});
