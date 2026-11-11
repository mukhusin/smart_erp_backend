<?php

use Illuminate\Support\Facades\Route;
use Modules\Hr\Http\Controllers\Api\ContractTypeController;
use Modules\Hr\Http\Controllers\Api\EmployeeContractController;
use Modules\Hr\Http\Controllers\Api\EmployeeController;
use Modules\Hr\Http\Controllers\Api\EmployeeDependantController;
use Modules\Hr\Http\Controllers\Api\EmployeeDocumentController;
use Modules\Hr\Http\Controllers\Api\EmployeeExperienceController;
use Modules\Hr\Http\Controllers\Api\EmployeeLicenceController;
use Modules\Hr\Http\Controllers\Api\EmployeeQualificationController;
use Modules\Hr\Http\Controllers\Api\LicenceTypeController;
use Modules\Hr\Http\Controllers\Api\LookupController;
use Modules\Hr\Http\Controllers\Api\NumberFormatController;

Route::prefix('hr')->middleware(['auth:sanctum', 'module:hr'])->name('hr.')->group(function () {
    Route::middleware('can:hr.employees.view')->group(function () {
        Route::get('options', [LookupController::class, 'options'])->name('options');
        Route::get('branches', [LookupController::class, 'branches'])->name('branches');
        Route::get('departments', [LookupController::class, 'departments'])->name('departments');
        Route::get('positions', [LookupController::class, 'positions'])->name('positions');
        Route::get('contract-types/active', [LookupController::class, 'contractTypes'])->name('contract-types.active');
        Route::get('licence-types/active', [LookupController::class, 'licenceTypes'])->name('licence-types.active');
        Route::get('summary', [LookupController::class, 'summary'])->name('summary');
        Route::get('number-formats/options', [NumberFormatController::class, 'options'])->name('number-formats.options');

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/next-number', [EmployeeController::class, 'nextNumber'])->middleware('can:hr.employees.create')->name('employees.next-number');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo');

        Route::prefix('employees/{employee}')->name('employees.')->scopeBindings()->group(function () {
            Route::get('contracts', [EmployeeContractController::class, 'index'])->name('contracts.index');
            Route::get('qualifications', [EmployeeQualificationController::class, 'index'])->name('qualifications.index');
            Route::get('qualifications/{qualification}/file', [EmployeeQualificationController::class, 'file'])->name('qualifications.file');
            Route::get('licences', [EmployeeLicenceController::class, 'index'])->name('licences.index');
            Route::get('licences/{licence}/file', [EmployeeLicenceController::class, 'file'])->name('licences.file');
            Route::get('experiences', [EmployeeExperienceController::class, 'index'])->name('experiences.index');
            Route::get('documents', [EmployeeDocumentController::class, 'index'])->name('documents.index');
            Route::get('documents/{document}/file', [EmployeeDocumentController::class, 'file'])->name('documents.file');
            Route::get('dependants', [EmployeeDependantController::class, 'index'])->name('dependants.index');
            Route::get('dependants/{dependant}/file', [EmployeeDependantController::class, 'file'])->name('dependants.file');
        });
    });

    Route::middleware('can:hr.employees.create')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::post('employees/{employee}/account', [EmployeeController::class, 'createAccount'])->name('employees.account');
        Route::post('employees/{employee}/invite', [EmployeeController::class, 'resendInvite'])->name('employees.invite');
    });

    Route::middleware('can:hr.employees.edit')->group(function () {
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::post('employees/{employee}/photo', [EmployeeController::class, 'uploadPhoto'])->name('employees.photo.upload');
        Route::delete('employees/{employee}/photo', [EmployeeController::class, 'deletePhoto'])->name('employees.photo.delete');

        Route::prefix('employees/{employee}')->name('employees.')->scopeBindings()->group(function () {
            Route::post('qualifications', [EmployeeQualificationController::class, 'store'])->name('qualifications.store');
            Route::post('qualifications/{qualification}', [EmployeeQualificationController::class, 'update'])->name('qualifications.update');
            Route::delete('qualifications/{qualification}', [EmployeeQualificationController::class, 'destroy'])->name('qualifications.destroy');

            Route::post('licences', [EmployeeLicenceController::class, 'store'])->name('licences.store');
            Route::post('licences/{licence}', [EmployeeLicenceController::class, 'update'])->name('licences.update');
            Route::delete('licences/{licence}', [EmployeeLicenceController::class, 'destroy'])->name('licences.destroy');

            Route::post('experiences', [EmployeeExperienceController::class, 'store'])->name('experiences.store');
            Route::put('experiences/{experience}', [EmployeeExperienceController::class, 'update'])->name('experiences.update');
            Route::delete('experiences/{experience}', [EmployeeExperienceController::class, 'destroy'])->name('experiences.destroy');

            Route::post('documents', [EmployeeDocumentController::class, 'store'])->name('documents.store');
            Route::post('documents/{document}', [EmployeeDocumentController::class, 'update'])->name('documents.update');
            Route::delete('documents/{document}', [EmployeeDocumentController::class, 'destroy'])->name('documents.destroy');

            Route::post('dependants', [EmployeeDependantController::class, 'store'])->name('dependants.store');
            Route::post('dependants/{dependant}', [EmployeeDependantController::class, 'update'])->name('dependants.update');
            Route::delete('dependants/{dependant}', [EmployeeDependantController::class, 'destroy'])->name('dependants.destroy');
        });
    });

    Route::middleware('can:hr.contracts.manage')->prefix('employees/{employee}')->name('employees.')->scopeBindings()->group(function () {
        Route::post('contracts', [EmployeeContractController::class, 'store'])->name('contracts.store');
        Route::put('contracts/{contract}', [EmployeeContractController::class, 'update'])->name('contracts.update');
        Route::post('contracts/{contract}/end', [EmployeeContractController::class, 'end'])->name('contracts.end');
        Route::post('contracts/{contract}/revert', [EmployeeContractController::class, 'revert'])->name('contracts.revert');
    });

    Route::middleware('can:hr.contract-types.manage')->group(function () {
        Route::get('contract-types', [ContractTypeController::class, 'index'])->name('contract-types.index');
        Route::post('contract-types', [ContractTypeController::class, 'store'])->name('contract-types.store');
        Route::put('contract-types/{contractType}', [ContractTypeController::class, 'update'])->name('contract-types.update');
        Route::delete('contract-types/{contractType}', [ContractTypeController::class, 'destroy'])->name('contract-types.destroy');
    });

    Route::middleware('can:hr.number-formats.manage')->group(function () {
        Route::get('number-formats', [NumberFormatController::class, 'index'])->name('number-formats.index');
        Route::post('number-formats', [NumberFormatController::class, 'store'])->name('number-formats.store');
        Route::put('number-formats/{numberFormat}', [NumberFormatController::class, 'update'])->name('number-formats.update');
        Route::delete('number-formats/{numberFormat}', [NumberFormatController::class, 'destroy'])->name('number-formats.destroy');
        Route::put('settings/numbering', [NumberFormatController::class, 'settings'])->name('settings.numbering');
    });

    Route::middleware('can:hr.licences.manage')->group(function () {
        Route::get('licence-types', [LicenceTypeController::class, 'index'])->name('licence-types.index');
        Route::post('licence-types', [LicenceTypeController::class, 'store'])->name('licence-types.store');
        Route::put('licence-types/{licenceType}', [LicenceTypeController::class, 'update'])->name('licence-types.update');
        Route::delete('licence-types/{licenceType}', [LicenceTypeController::class, 'destroy'])->name('licence-types.destroy');
    });
});
