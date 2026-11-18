<?php

use Illuminate\Support\Facades\Route;
use Modules\Fleet\Http\Controllers\Api\AgencyController;
use Modules\Fleet\Http\Controllers\Api\DriverController;
use Modules\Fleet\Http\Controllers\Api\FleetSetupController;
use Modules\Fleet\Http\Controllers\Api\FuelController;
use Modules\Fleet\Http\Controllers\Api\MyFleetController;
use Modules\Fleet\Http\Controllers\Api\TransportController;
use Modules\Fleet\Http\Controllers\Api\UnitController;

Route::middleware(['auth:sanctum', 'module:fleet'])->group(function () {
    Route::get('self/fleet', [MyFleetController::class, 'index'])->name('self.fleet');
    Route::get('self/fleet/trips', [TransportController::class, 'myTrips'])->name('self.fleet.trips');

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

        Route::get('requests', [TransportController::class, 'requests'])->name('requests.index');
        Route::post('requests', [TransportController::class, 'storeRequest'])->name('requests.store');
        Route::get('requests/{transportRequest}', [TransportController::class, 'showRequest'])->name('requests.show');
        Route::post('requests/{transportRequest}/withdraw', [TransportController::class, 'withdrawRequest'])->name('requests.withdraw');
        Route::post('requests/{transportRequest}/cancel', [TransportController::class, 'cancelRequest'])->name('requests.cancel');
        Route::post('requests/{transportRequest}/give-back', [TransportController::class, 'giveBack'])->name('requests.give-back');
        Route::get('availability', [TransportController::class, 'availability'])->name('availability');
        Route::get('trips', [TransportController::class, 'trips'])->name('trips.index');
        Route::post('trips/check', [TransportController::class, 'check'])->name('trips.check');
        Route::post('trips', [TransportController::class, 'dispatchTrip'])->name('trips.store');
        Route::get('trips/{trip}', [TransportController::class, 'showTrip'])->name('trips.show');
        Route::post('trips/{trip}/reschedule', [TransportController::class, 'reschedule'])->name('trips.reschedule');
        Route::post('trips/{trip}/cancel', [TransportController::class, 'cancelTrip'])->name('trips.cancel');
        Route::post('trips/{trip}/start', [TransportController::class, 'start'])->name('trips.start');
        Route::post('trips/{trip}/finish', [TransportController::class, 'finish'])->name('trips.finish');
        Route::post('trips/{trip}/confirm', [TransportController::class, 'confirm'])->name('trips.confirm');
        Route::get('contracts', [AgencyController::class, 'index'])->name('contracts.index');
        Route::post('contracts', [AgencyController::class, 'store'])->name('contracts.store');
        Route::put('contracts/{contract}', [AgencyController::class, 'update'])->name('contracts.update');
        Route::get('contracts/{contract}/unbatched', [AgencyController::class, 'unbatched'])->name('contracts.unbatched');
        Route::post('contracts/{contract}/batch', [AgencyController::class, 'batch'])->name('contracts.batch');

        Route::get('fuel', [FuelController::class, 'index'])->name('fuel.index');
        Route::get('fuel/form', [FuelController::class, 'form'])->name('fuel.form');
        Route::get('fuel/consumption', [FuelController::class, 'consumption'])->name('fuel.consumption');
        Route::post('fuel/check', [FuelController::class, 'check'])->name('fuel.check');
        Route::post('fuel', [FuelController::class, 'store'])->name('fuel.store');
        Route::get('fuel/{fuelRequest}', [FuelController::class, 'show'])->name('fuel.show');
        Route::post('fuel/{fuelRequest}/withdraw', [FuelController::class, 'withdraw'])->name('fuel.withdraw');
        Route::post('fuel/{fuelRequest}/cancel', [FuelController::class, 'cancel'])->name('fuel.cancel');
        Route::post('fuel/{fuelRequest}/issue', [FuelController::class, 'issue'])->name('fuel.issue');
        Route::get('fuel-stores', [FuelController::class, 'stores'])->name('fuel-stores.index');
        Route::post('fuel-stores', [FuelController::class, 'storeStore'])->name('fuel-stores.store');
        Route::put('fuel-stores/{store}', [FuelController::class, 'updateStore'])->name('fuel-stores.update');
        Route::get('fuel-stores/{store}/movements', [FuelController::class, 'movements'])->name('fuel-stores.movements');
        Route::post('fuel-stores/{store}/recharge', [FuelController::class, 'recharge'])->name('fuel-stores.recharge');
        Route::post('fuel-stores/{store}/adjust', [FuelController::class, 'adjust'])->name('fuel-stores.adjust');
        Route::post('fuel-stores/{store}/batch', [FuelController::class, 'batch'])->name('fuel-stores.batch');
        Route::post('fuel-recharges/{recharge}/receive', [FuelController::class, 'receive'])->name('fuel-recharges.receive');
        Route::post('fuel-recharges/{recharge}/cancel', [FuelController::class, 'cancelRecharge'])->name('fuel-recharges.cancel');

        Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
        Route::get('drivers/employees', [DriverController::class, 'employees'])->name('drivers.employees');
        Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
        Route::get('drivers/{driver}', [DriverController::class, 'show'])->name('drivers.show');
        Route::put('drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
        Route::post('drivers/{driver}/licences', [DriverController::class, 'licence'])->name('drivers.licences');
        Route::get('licences/{licence}/document', [DriverController::class, 'licenceFile'])->name('licences.document');
    });
});
