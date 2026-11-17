<?php

use Illuminate\Support\Facades\Route;
use Modules\Fleet\Http\Controllers\Api\DriverController;
use Modules\Fleet\Http\Controllers\Api\FleetSetupController;
use Modules\Fleet\Http\Controllers\Api\MyFleetController;
use Modules\Fleet\Http\Controllers\Api\UnitController;

Route::middleware(['auth:sanctum', 'module:fleet'])->group(function () {
    Route::get('self/fleet', [MyFleetController::class, 'index'])->name('self.fleet');

    Route::prefix('fleet')->name('fleet.')->group(function () {
        Route::get('dashboard', [FleetSetupController::class, 'dashboard'])->name('dashboard');
        Route::get('lookups', [FleetSetupController::class, 'lookups'])->name('lookups');
        Route::get('compliance', [FleetSetupController::class, 'compliance'])->name('compliance');
        Route::post('types', [FleetSetupController::class, 'storeType'])->name('types.store');
        Route::put('types/{type}', [FleetSetupController::class, 'updateType'])->name('types.update');

        Route::get('units', [UnitController::class, 'index'])->name('units.index');
        Route::post('units', [UnitController::class, 'store'])->name('units.store');
        Route::get('units/{unit}', [UnitController::class, 'show'])->name('units.show');
        Route::put('units/{unit}', [UnitController::class, 'update'])->name('units.update');
        Route::post('units/{unit}/status', [UnitController::class, 'status'])->name('units.status');
        Route::post('units/{unit}/readings', [UnitController::class, 'reading'])->name('units.readings');
        Route::post('units/{unit}/correction', [UnitController::class, 'correction'])->name('units.correction');
        Route::post('units/{unit}/assign', [UnitController::class, 'assign'])->name('units.assign');
        Route::post('units/{unit}/compliance', [UnitController::class, 'compliance'])->name('units.compliance');
        Route::post('units/{unit}/costs', [UnitController::class, 'cost'])->name('units.costs');
        Route::post('readings/{reading}/withdraw', [UnitController::class, 'withdrawCorrection'])->name('readings.withdraw');
        Route::post('assignments/{assignment}/end', [UnitController::class, 'endAssignment'])->name('assignments.end');
        Route::get('compliance/{compliance}/document', [UnitController::class, 'complianceFile'])->name('compliance.document');
        Route::delete('costs/{cost}', [UnitController::class, 'destroyCost'])->name('costs.destroy');

        Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::get('drivers/employees', [DriverController::class, 'employees'])->name('drivers.employees');
        Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::get('drivers/{driver}', [DriverController::class, 'show'])->name('drivers.show');
        Route::put('drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
        Route::post('drivers/{driver}/licences', [DriverController::class, 'licence'])->name('drivers.licences');
        Route::get('licences/{licence}/document', [DriverController::class, 'licenceFile'])->name('licences.document');
    });
});
