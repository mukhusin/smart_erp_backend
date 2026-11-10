<?php

use Illuminate\Support\Facades\Route;
use Modules\Hr\Http\Controllers\Api\EmployeeController;
use Modules\Hr\Http\Controllers\Api\LookupController;

Route::prefix('hr')->middleware(['auth:sanctum', 'module:hr'])->name('hr.')->group(function () {
    Route::middleware('can:hr.employees.view')->group(function () {
        Route::get('options', [LookupController::class, 'options'])->name('options');
        Route::get('branches', [LookupController::class, 'branches'])->name('branches');
        Route::get('departments', [LookupController::class, 'departments'])->name('departments');
        Route::get('positions', [LookupController::class, 'positions'])->name('positions');
        Route::get('summary', [LookupController::class, 'summary'])->name('summary');

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/next-number', [EmployeeController::class, 'nextNumber'])->middleware('can:hr.employees.create')->name('employees.next-number');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    });

    Route::middleware('can:hr.employees.create')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::post('employees/{employee}/account', [EmployeeController::class, 'createAccount'])->name('employees.account');
        Route::post('employees/{employee}/invite', [EmployeeController::class, 'resendInvite'])->name('employees.invite');
    });
});
