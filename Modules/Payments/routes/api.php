<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\Api\PaymentListController;
use Modules\Payments\Http\Controllers\Api\PaymentRequestController;
use Modules\Payments\Http\Controllers\Api\PaymentSetupController;
use Modules\Payments\Http\Controllers\Api\PaymentVoucherController;

Route::prefix('payments')->middleware(['auth:sanctum', 'module:payments'])->name('payments.')->group(function () {
    Route::get('settings', [PaymentSetupController::class, 'settings'])->name('settings.show');
    Route::put('settings', [PaymentSetupController::class, 'updateSettings'])->name('settings.update');
    Route::get('lookups', [PaymentSetupController::class, 'lookups'])->name('lookups');
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

    Route::get('vouchers', [PaymentVoucherController::class, 'index'])->name('vouchers.index');
    Route::get('vouchers/{voucher}', [PaymentVoucherController::class, 'show'])->name('vouchers.show');
    Route::post('vouchers/{voucher}/withdraw', [PaymentVoucherController::class, 'withdraw'])->name('vouchers.withdraw');
    Route::post('vouchers/{voucher}/void', [PaymentVoucherController::class, 'void'])->name('vouchers.void');

    Route::get('lists', [PaymentListController::class, 'index'])->name('lists.index');
    Route::post('lists', [PaymentListController::class, 'store'])->name('lists.store');
    Route::get('lists/{list}', [PaymentListController::class, 'show'])->name('lists.show');
    Route::post('lists/{list}/withdraw', [PaymentListController::class, 'withdraw'])->name('lists.withdraw');
    Route::put('lists/{list}/references', [PaymentListController::class, 'references'])->name('lists.references');
});
