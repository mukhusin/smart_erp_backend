<?php

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\Api\SupplierController;

Route::prefix('procurement')->middleware(['auth:sanctum', 'module:procurement'])->name('procurement.')->group(function () {
    Route::get('lookups', [SupplierController::class, 'lookups'])->name('lookups');
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
