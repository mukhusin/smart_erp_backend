<?php

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\Api\OrderController;
use Modules\Procurement\Http\Controllers\Api\QuotationController;
use Modules\Procurement\Http\Controllers\Api\ReceiptController;
use Modules\Procurement\Http\Controllers\Api\RequisitionController;
use Modules\Procurement\Http\Controllers\Api\SupplierController;

Route::prefix('procurement')->middleware(['auth:sanctum', 'module:procurement'])->name('procurement.')->group(function () {
    Route::get('lookups', [SupplierController::class, 'lookups'])->name('lookups');
    Route::get('form-lookups', [RequisitionController::class, 'lookups'])->name('form-lookups');
    Route::get('budget-lines', [RequisitionController::class, 'budgetLines'])->name('budget-lines');
    Route::get('settings', [RequisitionController::class, 'settings'])->name('settings.show');
    Route::put('settings', [RequisitionController::class, 'updateSettings'])->name('settings.update');

    Route::get('requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::post('requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::get('requisitions/{requisition}', [RequisitionController::class, 'show'])->name('requisitions.show');
    Route::put('requisitions/{requisition}', [RequisitionController::class, 'update'])->name('requisitions.update');
    Route::post('requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');
    Route::post('requisitions/{requisition}/withdraw', [RequisitionController::class, 'withdraw'])->name('requisitions.withdraw');
    Route::post('requisitions/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('requisitions.cancel');
    Route::post('requisitions/{requisition}/rfqs', [QuotationController::class, 'store'])->name('rfqs.store');
    Route::post('requisitions/{requisition}/orders', [OrderController::class, 'store'])->name('orders.store');

    Route::get('rfqs', [QuotationController::class, 'index'])->name('rfqs.index');
    Route::get('rfqs/{rfq}', [QuotationController::class, 'show'])->name('rfqs.show');
    Route::post('rfqs/{rfq}/quotations', [QuotationController::class, 'record'])->name('rfqs.record');
    Route::get('rfqs/{rfq}/quotations/{quotation}/file', [QuotationController::class, 'file'])->name('rfqs.file');
    Route::post('rfqs/{rfq}/award', [QuotationController::class, 'award'])->name('rfqs.award');
    Route::post('rfqs/{rfq}/cancel', [QuotationController::class, 'cancel'])->name('rfqs.cancel');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('orders/{order}/pdf', [OrderController::class, 'pdf'])->name('orders.pdf');
    Route::post('orders/{order}/submit', [OrderController::class, 'submit'])->name('orders.submit');
    Route::post('orders/{order}/withdraw', [OrderController::class, 'withdraw'])->name('orders.withdraw');
    Route::post('orders/{order}/acknowledge', [OrderController::class, 'acknowledge'])->name('orders.acknowledge');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{order}/receipts', [ReceiptController::class, 'store'])->name('receipts.store');

    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::post('receipts/{receipt}/submit', [ReceiptController::class, 'submit'])->name('receipts.submit');
    Route::post('receipts/{receipt}/withdraw', [ReceiptController::class, 'withdraw'])->name('receipts.withdraw');
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel'])->name('receipts.cancel');
    Route::put('receipts/{receipt}/invoice', [ReceiptController::class, 'invoice'])->name('receipts.invoice');
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::put('suppliers/{supplier}/blacklist', [SupplierController::class, 'blacklist'])->name('suppliers.blacklist');
    Route::post('suppliers/{supplier}/bank-accounts', [SupplierController::class, 'storeBank'])->name('suppliers.banks.store');
    Route::post('suppliers/{supplier}/bank-accounts/{account}/withdraw', [SupplierController::class, 'withdrawBank'])->name('suppliers.banks.withdraw');
    Route::post('suppliers/{supplier}/bank-accounts/{account}/disable', [SupplierController::class, 'disableBank'])->name('suppliers.banks.disable');
    Route::post('suppliers/{supplier}/documents', [SupplierController::class, 'storeDocument'])->name('suppliers.documents.store');
    Route::get('suppliers/{supplier}/documents/{document}', [SupplierController::class, 'file'])->name('suppliers.documents.file');
    Route::delete('suppliers/{supplier}/documents/{document}', [SupplierController::class, 'destroyDocument'])->name('suppliers.documents.destroy');
});
