<?php

use Illuminate\Support\Facades\Route;
use Modules\Hr\Http\Controllers\Api\Allowance\AllowanceEntryController;
use Modules\Hr\Http\Controllers\Api\Allowance\AllowanceSetupController;
use Modules\Hr\Http\Controllers\Api\BankController;
use Modules\Hr\Http\Controllers\Api\Overtime\OvertimeController;
use Modules\Hr\Http\Controllers\Api\Payroll\PayrollController;
use Modules\Hr\Http\Controllers\Api\ContractTypeController;
use Modules\Hr\Http\Controllers\Api\DeductionController;
use Modules\Hr\Http\Controllers\Api\DepartmentController;
use Modules\Hr\Http\Controllers\Api\EmployeeBankAccountController;
use Modules\Hr\Http\Controllers\Api\EmployeeContractController;
use Modules\Hr\Http\Controllers\Api\EmployeeController;
use Modules\Hr\Http\Controllers\Api\EmployeeDependantController;
use Modules\Hr\Http\Controllers\Api\EmployeeDocumentController;
use Modules\Hr\Http\Controllers\Api\EmployeeExperienceController;
use Modules\Hr\Http\Controllers\Api\EmployeeLicenceController;
use Modules\Hr\Http\Controllers\Api\EmployeeQualificationController;
use Modules\Hr\Http\Controllers\Api\JobPositionController;
use Modules\Hr\Http\Controllers\Api\Leave\AnnualRosterController;
use Modules\Hr\Http\Controllers\Api\Leave\LeaveBalanceController;
use Modules\Hr\Http\Controllers\Api\Leave\LeaveRequestController;
use Modules\Hr\Http\Controllers\Api\Leave\SelfLeaveController;
use Modules\Hr\Http\Controllers\Api\Leave\LeaveSettingsController;
use Modules\Hr\Http\Controllers\Api\Leave\LeaveTypeController;
use Modules\Hr\Http\Controllers\Api\LicenceTypeController;
use Modules\Hr\Http\Controllers\Api\Loans\LoansController;
use Modules\Hr\Http\Controllers\Api\LookupController;
use Modules\Hr\Http\Controllers\Api\NumberFormatController;
use Modules\Hr\Http\Controllers\Api\PlacementController;
use Modules\Hr\Http\Controllers\Api\RenewalController;
use Modules\Hr\Http\Controllers\Api\RenewalLetterController;
use Modules\Hr\Http\Controllers\Api\RenewalReviewController;
use Modules\Hr\Http\Controllers\Api\Roster\PublicHolidayController;
use Modules\Hr\Http\Controllers\Api\Roster\RosterCodeController;
use Modules\Hr\Http\Controllers\Api\Roster\RosterController;
use Modules\Hr\Http\Controllers\Api\Roster\WorkScheduleController;
use Modules\Hr\Http\Controllers\Api\SelfContractController;
use Modules\Hr\Http\Controllers\Api\SelfDutyController;
use Modules\Hr\Http\Controllers\Api\SupervisorController;

Route::prefix('hr')->middleware(['auth:sanctum', 'module:hr', 'hr.scope'])->name('hr.')->group(function () {
    Route::group([], function () {
        Route::get('options', [LookupController::class, 'options'])->name('options');
        Route::get('branches', [LookupController::class, 'branches'])->name('branches');
        Route::get('departments', [LookupController::class, 'departments'])->name('departments');
        Route::get('positions', [LookupController::class, 'positions'])->name('positions');
        Route::get('contract-types/active', [LookupController::class, 'contractTypes'])->name('contract-types.active');
        Route::get('licence-types/active', [LookupController::class, 'licenceTypes'])->name('licence-types.active');
        Route::get('summary', [LookupController::class, 'summary'])->name('summary');
        Route::get('placements', [PlacementController::class, 'index'])->name('placements.index');
        Route::get('job-positions', [JobPositionController::class, 'index'])->name('job-positions.index');
        Route::get('renewals', [RenewalController::class, 'index'])->name('renewals.index');
        Route::get('renewals/{renewal}/files/{file}', [RenewalController::class, 'file'])->name('renewals.file');
        Route::get('banks/active', [BankController::class, 'active'])->name('banks.active');
        Route::get('structure/departments', [DepartmentController::class, 'index'])->name('structure.index');
        Route::get('structure/departments/{department}', [DepartmentController::class, 'show'])->name('structure.show');
        Route::get('number-formats/options', [NumberFormatController::class, 'options'])->name('number-formats.options');

        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/next-number', [EmployeeController::class, 'nextNumber'])->middleware('can:hr.employees.create')->name('employees.next-number');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo');

        Route::prefix('employees/{employee}')->name('employees.')->scopeBindings()->group(function () {
            Route::get('contracts', [EmployeeContractController::class, 'index'])->name('contracts.index');
            Route::get('placements', [PlacementController::class, 'employeeIndex'])->name('placements.index');
            Route::get('renewals', [RenewalController::class, 'employeeIndex'])->name('renewals.index');
            Route::get('supervisors', [SupervisorController::class, 'index'])->name('supervisors.index');
            Route::get('deductions', [DeductionController::class, 'index'])->name('deductions.index');
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
        Route::put('employees/{employee}/bank-account', [EmployeeBankAccountController::class, 'update'])->name('employees.bank-account.update');
        Route::delete('employees/{employee}/bank-account', [EmployeeBankAccountController::class, 'destroy'])->name('employees.bank-account.destroy');
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

            Route::post('supervisors', [SupervisorController::class, 'store'])->name('supervisors.store');
            Route::post('supervisors/end', [SupervisorController::class, 'end'])->name('supervisors.end');
            Route::delete('supervisors/{supervision}', [SupervisorController::class, 'destroy'])->name('supervisors.destroy');

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
        Route::post('renewals', [RenewalController::class, 'store'])->name('renewals.store');
    });

    Route::middleware('can:hr.contracts.manage')->group(function () {
        Route::get('settings/renewal-letter', [RenewalLetterController::class, 'index'])->name('settings.renewal-letter.index');
        Route::put('settings/renewal-letter/{language}', [RenewalLetterController::class, 'update'])->name('settings.renewal-letter.update');
        Route::post('settings/renewal-letter/{language}/reset', [RenewalLetterController::class, 'reset'])->name('settings.renewal-letter.reset');
        Route::post('renewals/{renewal}/contract', [RenewalController::class, 'complete'])->name('renewals.complete');
        Route::post('renewals/{renewal}/withdraw', [RenewalController::class, 'withdraw'])->name('renewals.withdraw');
    });

    Route::middleware('can:hr.contract-types.manage')->group(function () {
        Route::get('contract-types', [ContractTypeController::class, 'index'])->name('contract-types.index');
        Route::post('contract-types', [ContractTypeController::class, 'store'])->name('contract-types.store');
        Route::put('contract-types/{contractType}', [ContractTypeController::class, 'update'])->name('contract-types.update');
        Route::delete('contract-types/{contractType}', [ContractTypeController::class, 'destroy'])->name('contract-types.destroy');
    });

    Route::middleware('can:hr.residents.manage')->prefix('employees/{employee}')->name('employees.')->scopeBindings()->group(function () {
        Route::post('placements', [PlacementController::class, 'store'])->name('placements.store');
        Route::put('placements/{placement}', [PlacementController::class, 'update'])->name('placements.update');
        Route::post('placements/{placement}/stop', [PlacementController::class, 'stop'])->name('placements.stop');
        Route::delete('placements/{placement}', [PlacementController::class, 'destroy'])->name('placements.destroy');
    });

    Route::middleware('can:hr.banks.manage')->group(function () {
        Route::get('banks', [BankController::class, 'index'])->name('banks.index');
        Route::post('banks', [BankController::class, 'store'])->name('banks.store');
        Route::put('banks/{bank}', [BankController::class, 'update'])->name('banks.update');
        Route::delete('banks/{bank}', [BankController::class, 'destroy'])->name('banks.destroy');
    });

    Route::middleware('can:payroll.statutory.manage')->prefix('employees/{employee}')->name('employees.')->group(function () {
        Route::put('deductions/{scheme}', [DeductionController::class, 'update'])->name('deductions.update');
        Route::delete('deductions/{scheme}', [DeductionController::class, 'destroy'])->name('deductions.destroy');
    });

    Route::middleware('can:hr.positions.manage')->group(function () {
        Route::post('job-positions', [JobPositionController::class, 'store'])->name('job-positions.store');
        Route::put('job-positions/{jobPosition}', [JobPositionController::class, 'update'])->name('job-positions.update');
        Route::delete('job-positions/{jobPosition}', [JobPositionController::class, 'destroy'])->name('job-positions.destroy');
    });

    Route::middleware('can:hr.departments.manage')->prefix('structure/departments')->name('structure.')->group(function () {
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::put('{department}', [DepartmentController::class, 'update'])->name('update');
        Route::delete('{department}', [DepartmentController::class, 'destroy'])->name('destroy');
        Route::post('{department}/head', [DepartmentController::class, 'appointHead'])->name('head');
        Route::post('{department}/head/remove', [DepartmentController::class, 'removeHead'])->name('head.remove');
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

Route::prefix('self')->middleware(['auth:sanctum', 'module:self-service', 'can:self-service.use'])->name('self.')->group(function () {
    Route::get('contract', [SelfContractController::class, 'show'])->name('contract');
    Route::get('duties', [SelfDutyController::class, 'index'])->name('duties');
    Route::get('allowances', [AllowanceEntryController::class, 'mine'])->middleware('module:allowance')->name('allowances');
    Route::middleware('module:loans')->group(function () {
        Route::get('loans', [LoansController::class, 'mine'])->name('loans.index');
        Route::post('advances', [LoansController::class, 'requestAdvance'])->name('advances.store');
        Route::post('advances/{advance}/withdraw', [LoansController::class, 'withdrawAdvance'])->name('advances.withdraw');
    });
    Route::middleware('module:payroll')->group(function () {
        Route::get('payslips', [PayrollController::class, 'myPayslips'])->name('payslips.index');
        Route::get('payslips/{detail}', [PayrollController::class, 'myPayslip'])->name('payslips.show');
    });
    Route::middleware('module:overtime')->group(function () {
        Route::get('overtime', [OvertimeController::class, 'mine'])->name('overtime.index');
        Route::get('overtime/preview', [OvertimeController::class, 'preview'])->name('overtime.preview');
        Route::post('overtime', [OvertimeController::class, 'storeMine'])->name('overtime.store');
        Route::post('overtime/{overtime}/withdraw', [OvertimeController::class, 'withdraw'])->name('overtime.withdraw');
    });
    Route::middleware('module:leave')->group(function () {
        Route::get('leave/balances', [LeaveBalanceController::class, 'mine'])->name('leave.balances');
        Route::get('leave/days', [LeaveBalanceController::class, 'myDays'])->name('leave.days');
        Route::get('leave/plans', [AnnualRosterController::class, 'mine'])->name('leave.plans');
        Route::get('leave/requests', [SelfLeaveController::class, 'index'])->name('leave.index');
        Route::post('leave/requests', [SelfLeaveController::class, 'store'])->name('leave.store');
        Route::post('leave/requests/{leave}/withdraw', [SelfLeaveController::class, 'withdraw'])->name('leave.withdraw');
        Route::get('leave/requests/{leave}/files/{file}', [SelfLeaveController::class, 'file'])->name('leave.file');
    });
    Route::post('renewals', [SelfContractController::class, 'store'])->name('renewals.store');
    Route::post('renewals/{renewal}/withdraw', [SelfContractController::class, 'withdraw'])->name('renewals.withdraw');
    Route::get('renewals/{renewal}/files/{file}', [SelfContractController::class, 'file'])->name('renewals.file');
});

Route::prefix('roster')->middleware(['auth:sanctum', 'module:roster'])->name('roster.')->group(function () {
    Route::get('rosters', [RosterController::class, 'index'])->name('rosters.index');
    Route::get('rosters/options', [RosterController::class, 'options'])->name('rosters.options');
    Route::get('rosters/{roster}', [RosterController::class, 'show'])->name('rosters.show');
    Route::post('rosters', [RosterController::class, 'store'])->name('rosters.store');
    Route::put('rosters/{roster}', [RosterController::class, 'update'])->name('rosters.update');
    Route::delete('rosters/{roster}', [RosterController::class, 'destroy'])->name('rosters.destroy');
    Route::put('rosters/{roster}/duties', [RosterController::class, 'duties'])->name('rosters.duties');
    Route::post('rosters/{roster}/submit', [RosterController::class, 'submit'])->name('rosters.submit');
    Route::post('rosters/{roster}/withdraw', [RosterController::class, 'withdraw'])->name('rosters.withdraw');

    Route::middleware('can:roster.timetables.manage')->group(function () {
        Route::get('codes', [RosterCodeController::class, 'index'])->name('codes.index');
        Route::post('codes', [RosterCodeController::class, 'store'])->name('codes.store');
        Route::put('codes/{rosterCode}', [RosterCodeController::class, 'update'])->name('codes.update');
        Route::delete('codes/{rosterCode}', [RosterCodeController::class, 'destroy'])->name('codes.destroy');
        Route::get('work-schedules', [WorkScheduleController::class, 'index'])->name('work-schedules.index');
        Route::post('work-schedules', [WorkScheduleController::class, 'store'])->name('work-schedules.store');
        Route::put('work-schedules/{workSchedule}', [WorkScheduleController::class, 'update'])->name('work-schedules.update');
        Route::delete('work-schedules/{workSchedule}', [WorkScheduleController::class, 'destroy'])->name('work-schedules.destroy');
    });

    Route::middleware('can:roster.holidays.manage')->group(function () {
        Route::get('holidays', [PublicHolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [PublicHolidayController::class, 'store'])->name('holidays.store');
        Route::put('holidays/{publicHoliday}', [PublicHolidayController::class, 'update'])->name('holidays.update');
        Route::delete('holidays/{publicHoliday}', [PublicHolidayController::class, 'destroy'])->name('holidays.destroy');
    });
});

Route::prefix('renewals')->middleware(['auth:sanctum', 'module:hr'])->name('renewal-review.')->group(function () {
    Route::get('{renewal}/review', [RenewalReviewController::class, 'show'])->name('show');
    Route::put('{renewal}/terms', [RenewalReviewController::class, 'terms'])->name('terms');
});

Route::prefix('leave')->middleware(['auth:sanctum', 'module:leave'])->name('leave.')->group(function () {
    Route::middleware('can:leave.types.manage')->group(function () {
        Route::get('types', [LeaveTypeController::class, 'index'])->name('types.index');
        Route::post('types', [LeaveTypeController::class, 'store'])->name('types.store');
        Route::put('types/{leaveType}', [LeaveTypeController::class, 'update'])->name('types.update');
        Route::delete('types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('types.destroy');
        Route::get('settings', [LeaveSettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [LeaveSettingsController::class, 'update'])->name('settings.update');
    });
    Route::get('annual-rosters', [AnnualRosterController::class, 'index'])->name('annual-rosters.index');
    Route::post('annual-rosters', [AnnualRosterController::class, 'store'])->name('annual-rosters.store');
    Route::get('annual-rosters/{roster}', [AnnualRosterController::class, 'show'])->name('annual-rosters.show');
    Route::delete('annual-rosters/{roster}', [AnnualRosterController::class, 'destroy'])->name('annual-rosters.destroy');
    Route::post('annual-rosters/{roster}/publish', [AnnualRosterController::class, 'publish'])->name('annual-rosters.publish');
    Route::post('annual-rosters/{roster}/plans', [AnnualRosterController::class, 'storePlan'])->name('annual-rosters.plans.store');
    Route::put('annual-rosters/{roster}/plans/{plan}', [AnnualRosterController::class, 'updatePlan'])->name('annual-rosters.plans.update');
    Route::delete('annual-rosters/{roster}/plans/{plan}', [AnnualRosterController::class, 'destroyPlan'])->name('annual-rosters.plans.destroy');
    Route::get('requests', [LeaveRequestController::class, 'index'])->name('requests.index');
    Route::get('requests/{leave}', [LeaveRequestController::class, 'review'])->name('requests.review');
    Route::put('requests/{leave}/dates', [LeaveRequestController::class, 'dates'])->name('requests.dates');
    Route::post('requests/{leave}/return', [LeaveRequestController::class, 'recordReturn'])->name('requests.return');
    Route::get('requests/{leave}/files/{file}', [LeaveRequestController::class, 'file'])->name('requests.file');
    Route::post('employees/{employee}/requests', [LeaveRequestController::class, 'storeFor'])->name('requests.store-for');
    Route::get('employees/{employee}/balances', [LeaveBalanceController::class, 'employee'])->name('balances.employee');
    Route::get('employees/{employee}/days', [LeaveBalanceController::class, 'employeeDays'])->name('days.employee');
    Route::middleware('can:leave.balances.manage')->group(function () {
        Route::post('employees/{employee}/adjustments', [LeaveBalanceController::class, 'adjust'])->name('adjustments.store');
        Route::delete('employees/{employee}/adjustments/{adjustment}', [LeaveBalanceController::class, 'removeAdjustment'])->name('adjustments.destroy');
    });
});

Route::prefix('allowance')->middleware(['auth:sanctum', 'module:allowance'])->name('allowance.')->group(function () {
    Route::middleware('can:allowance.categories.manage')->group(function () {
        Route::get('categories', [AllowanceSetupController::class, 'categories'])->name('categories.index');
        Route::post('categories', [AllowanceSetupController::class, 'storeCategory'])->name('categories.store');
        Route::put('categories/{category}', [AllowanceSetupController::class, 'updateCategory'])->name('categories.update');
        Route::delete('categories/{category}', [AllowanceSetupController::class, 'destroyCategory'])->name('categories.destroy');
    });
    Route::middleware('can:allowance.department-ranges.manage')->group(function () {
        Route::get('rates', [AllowanceSetupController::class, 'rates'])->name('rates.index');
        Route::post('rates', [AllowanceSetupController::class, 'storeRate'])->name('rates.store');
        Route::put('rates/{rate}', [AllowanceSetupController::class, 'updateRate'])->name('rates.update');
        Route::delete('rates/{rate}', [AllowanceSetupController::class, 'destroyRate'])->name('rates.destroy');
    });
    Route::get('entries', [AllowanceEntryController::class, 'index'])->name('entries.index');
    Route::middleware('can:allowance.daily.manage')->group(function () {
        Route::post('generate', [AllowanceEntryController::class, 'generate'])->name('generate');
        Route::post('employees/{employee}/claims', [AllowanceEntryController::class, 'claim'])->name('claims.store');
    });
});

Route::prefix('overtime')->middleware(['auth:sanctum', 'module:overtime'])->name('overtime.')->group(function () {
    Route::middleware('can:overtime.department-ranges.manage')->group(function () {
        Route::get('rates', [OvertimeController::class, 'rates'])->name('rates.index');
        Route::post('rates', [OvertimeController::class, 'storeRate'])->name('rates.store');
        Route::put('rates/{rate}', [OvertimeController::class, 'updateRate'])->name('rates.update');
        Route::delete('rates/{rate}', [OvertimeController::class, 'destroyRate'])->name('rates.destroy');
    });
    Route::get('claims', [OvertimeController::class, 'index'])->name('claims.index');
    Route::get('claims/{overtime}', [OvertimeController::class, 'review'])->name('claims.review');
    Route::put('claims/{overtime}/times', [OvertimeController::class, 'times'])->name('claims.times');
    Route::post('employees/{employee}/claims', [OvertimeController::class, 'storeFor'])->middleware('can:overtime.manage')->name('claims.store-for');
});

Route::prefix('payroll')->middleware(['auth:sanctum', 'module:payroll'])->name('payroll.')->group(function () {
    Route::middleware('can:payroll.edit')->group(function () {
        Route::get('settings', [PayrollController::class, 'settings'])->name('settings.show');
        Route::put('settings', [PayrollController::class, 'updateSettings'])->name('settings.update');
        Route::get('settings/paye', [PayrollController::class, 'payeCheck'])->name('settings.paye');
    });
    Route::middleware('can:payroll.view')->group(function () {
        Route::get('sheets', [PayrollController::class, 'index'])->name('sheets.index');
        Route::get('sheets/{sheet}', [PayrollController::class, 'show'])->name('sheets.show');
        Route::get('sheets/{sheet}/payslips/{detail}', [PayrollController::class, 'payslip'])->name('sheets.payslip');
        Route::get('sheets/{sheet}/reports/{type}', [PayrollController::class, 'report'])->name('sheets.report');
        Route::get('sheets/{sheet}/reports/{type}/csv', [PayrollController::class, 'reportCsv'])->name('sheets.report.csv');
    });
    Route::middleware('can:payroll.create')->group(function () {
        Route::post('sheets', [PayrollController::class, 'store'])->name('sheets.store');
        Route::post('sheets/{sheet}/generate', [PayrollController::class, 'regenerate'])->name('sheets.generate');
        Route::post('sheets/{sheet}/submit', [PayrollController::class, 'submit'])->name('sheets.submit');
        Route::post('sheets/{sheet}/withdraw', [PayrollController::class, 'withdraw'])->name('sheets.withdraw');
        Route::delete('sheets/{sheet}', [PayrollController::class, 'destroy'])->name('sheets.destroy');
    });
});

Route::prefix('loans')->middleware(['auth:sanctum', 'module:loans'])->name('loans.')->group(function () {
    Route::middleware('can:loans.view')->group(function () {
        Route::get('providers', [LoansController::class, 'providers'])->name('providers.index');
        Route::get('loans', [LoansController::class, 'index'])->name('loans.index');
        Route::get('loans/{loan}', [LoansController::class, 'show'])->name('loans.show');
        Route::get('advances', [LoansController::class, 'advances'])->name('advances.index');
    });
    Route::middleware('can:loans.manage')->group(function () {
        Route::post('providers', [LoansController::class, 'storeProvider'])->name('providers.store');
        Route::put('providers/{provider}', [LoansController::class, 'updateProvider'])->name('providers.update');
        Route::delete('providers/{provider}', [LoansController::class, 'destroyProvider'])->name('providers.destroy');
        Route::post('employees/{employee}/loans', [LoansController::class, 'store'])->name('loans.store');
        Route::put('loans/{loan}', [LoansController::class, 'update'])->name('loans.update');
        Route::post('loans/{loan}/pause', [LoansController::class, 'pause'])->name('loans.pause');
        Route::delete('loans/{loan}', [LoansController::class, 'destroy'])->name('loans.destroy');
        Route::post('employees/{employee}/advances', [LoansController::class, 'storeAdvance'])->name('advances.store');
        Route::post('advances/{advance}/cancel', [LoansController::class, 'cancelAdvance'])->name('advances.cancel');
    });
});
