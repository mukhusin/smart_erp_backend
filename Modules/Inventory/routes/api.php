<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\CatalogueController;
use Modules\Inventory\Http\Controllers\Api\StockController;
use Modules\Inventory\Http\Controllers\Api\StockDocumentController;
use Modules\Inventory\Http\Controllers\Api\StoreController;
use Modules\Inventory\Http\Controllers\Api\StoreRequisitionController;

Route::prefix('inventory')->middleware(['auth:sanctum', 'module:inventory'])->name('inventory.')->group(function () {
    Route::get('lookups', [CatalogueController::class, 'lookups'])->name('lookups');
    Route::post('units', [CatalogueController::class, 'storeUnit'])->name('units.store');
    Route::put('units/{unit}', [CatalogueController::class, 'updateUnit'])->name('units.update');
    Route::get('categories', [CatalogueController::class, 'categories'])->name('categories.index');
    Route::post('categories', [CatalogueController::class, 'storeCategory'])->name('categories.store');
    Route::put('categories/{category}', [CatalogueController::class, 'updateCategory'])->name('categories.update');
    Route::get('items', [CatalogueController::class, 'items'])->name('items.index');
    Route::get('items/template', [CatalogueController::class, 'template'])->name('items.template');
    Route::post('items/import', [CatalogueController::class, 'import'])->name('items.import');
    Route::post('items', [CatalogueController::class, 'storeItem'])->name('items.store');
    Route::get('items/{item}', [CatalogueController::class, 'item'])->name('items.show');
    Route::put('items/{item}', [CatalogueController::class, 'updateItem'])->name('items.update');
    Route::put('items/{item}/levels', [CatalogueController::class, 'levels'])->name('items.levels');

    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/available', [StockController::class, 'available'])->name('stock.available');
    Route::get('stock/items/{item}', [StockController::class, 'card'])->name('stock.card');
    Route::get('stock-settings', [StockController::class, 'settings'])->name('stock-settings.show');
    Route::put('stock-settings', [StockController::class, 'updateSettings'])->name('stock-settings.update');

    Route::get('requests', [StoreRequisitionController::class, 'index'])->name('requests.index');
    Route::get('requests/lookups', [StoreRequisitionController::class, 'lookups'])->name('requests.lookups');
    Route::post('requests', [StoreRequisitionController::class, 'store'])->name('requests.store');
    Route::get('requests/{storeRequest}', [StoreRequisitionController::class, 'show'])->name('requests.show');
    Route::put('requests/{storeRequest}', [StoreRequisitionController::class, 'update'])->name('requests.update');
    Route::post('requests/{storeRequest}/submit', [StoreRequisitionController::class, 'submit'])->name('requests.submit');
    Route::post('requests/{storeRequest}/withdraw', [StoreRequisitionController::class, 'withdraw'])->name('requests.withdraw');
    Route::post('requests/{storeRequest}/cancel', [StoreRequisitionController::class, 'cancel'])->name('requests.cancel');

    Route::get('documents', [StockDocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [StockDocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [StockDocumentController::class, 'show'])->name('documents.show');
    Route::put('documents/{document}/counts', [StockDocumentController::class, 'counts'])->name('documents.counts');
    Route::post('documents/{document}/submit', [StockDocumentController::class, 'submit'])->name('documents.submit');
    Route::post('documents/{document}/withdraw', [StockDocumentController::class, 'withdraw'])->name('documents.withdraw');
    Route::post('documents/{document}/cancel', [StockDocumentController::class, 'cancel'])->name('documents.cancel');
    Route::post('documents/{document}/confirm', [StockDocumentController::class, 'confirm'])->name('documents.confirm');
    Route::post('documents/{document}/receive', [StockDocumentController::class, 'receive'])->name('documents.receive');
    Route::post('documents/{document}/reverse', [StockDocumentController::class, 'reverse'])->name('documents.reverse');

    Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('stores/staff', [StoreController::class, 'staff'])->name('stores.staff');
    Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
    Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
    Route::put('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
    Route::put('stores/{store}/users', [StoreController::class, 'users'])->name('stores.users');
    Route::post('stores/{store}/bins', [StoreController::class, 'storeBin'])->name('stores.bins.store');
    Route::delete('stores/{store}/bins/{bin}', [StoreController::class, 'destroyBin'])->name('stores.bins.destroy');
});
