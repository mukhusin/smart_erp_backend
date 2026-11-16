<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\CatalogueController;
use Modules\Inventory\Http\Controllers\Api\StoreController;

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

    Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('stores/staff', [StoreController::class, 'staff'])->name('stores.staff');
    Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
    Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
    Route::put('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
    Route::put('stores/{store}/users', [StoreController::class, 'users'])->name('stores.users');
    Route::post('stores/{store}/bins', [StoreController::class, 'storeBin'])->name('stores.bins.store');
    Route::delete('stores/{store}/bins/{bin}', [StoreController::class, 'destroyBin'])->name('stores.bins.destroy');
});
