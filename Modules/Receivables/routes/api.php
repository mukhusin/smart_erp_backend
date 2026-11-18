<?php

use Illuminate\Support\Facades\Route;
use Modules\Receivables\Http\Controllers\Api\ClaimController;
use Modules\Receivables\Http\Controllers\Api\CustomerController;
use Modules\Receivables\Http\Controllers\Api\InvoiceController;
use Modules\Receivables\Http\Controllers\Api\ReceiptController;
use Modules\Receivables\Http\Controllers\Api\ReceivableReportController;
use Modules\Receivables\Http\Controllers\Api\ReceivableSetupController;

Route::prefix('receivables')->middleware(['auth:sanctum', 'module:receivables'])->name('receivables.')->group(function () {
    Route::get('settings', [ReceivableSetupController::class, 'settings'])->name('settings.show');
    Route::put('settings', [ReceivableSetupController::class, 'updateSettings'])->name('settings.update');
    Route::get('lookups', [ReceivableSetupController::class, 'lookups'])->name('lookups');
    Route::get('dashboard', [ReceivableReportController::class, 'dashboard'])->name('dashboard');
    Route::get('ageing', [ReceivableReportController::class, 'ageing'])->name('ageing');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/withdraw', [InvoiceController::class, 'withdraw'])->name('invoices.withdraw');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('invoices/{invoice}/credit-notes', [InvoiceController::class, 'credit'])->name('credit-notes.store');
    Route::post('invoices/{invoice}/credit-notes/{creditNote}/withdraw', [InvoiceController::class, 'withdrawCredit'])->name('credit-notes.withdraw');

    Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::post('claims', [ClaimController::class, 'store'])->name('claims.store');
    Route::get('claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');
    Route::put('claims/{claim}', [ClaimController::class, 'update'])->name('claims.update');
    Route::post('claims/{claim}/submit', [ClaimController::class, 'submit'])->name('claims.submit');
    Route::post('claims/{claim}/respond', [ClaimController::class, 'respond'])->name('claims.respond');
    Route::post('claims/{claim}/lines/{line}/resolve', [ClaimController::class, 'resolve'])->name('claims.resolve');
    Route::post('claims/{claim}/cancel', [ClaimController::class, 'cancel'])->name('claims.cancel');

    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::post('receipts/{receipt}/void', [ReceiptController::class, 'void'])->name('receipts.void');
});
