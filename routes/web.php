<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
});

Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Profile (stub)
    Route::get('/profile', fn() => view('dashboard.index'))->name('profile.edit');

    // ── HR ──────────────────────────────────────────────────────
    Route::prefix('hr')->name('hr.')->middleware('module:hr')->group(function () {
        Route::resource('departments',   \App\Http\Controllers\HR\DepartmentController::class);
        Route::resource('positions',     \App\Http\Controllers\HR\JobPositionController::class);
        Route::resource('salary-scales', \App\Http\Controllers\HR\SalaryScaleController::class);
        Route::resource('contract-types',\App\Http\Controllers\HR\ContractTypeController::class);

        $ec = \App\Http\Controllers\HR\EmployeeController::class;
        Route::resource('employees', $ec);
        // Employee sub-resources
        Route::post('employees/{employee}/qualifications',            [$ec,'storeQualification'])->name('employees.qualifications.store');
        Route::delete('employees/{employee}/qualifications/{qualification}', [$ec,'destroyQualification'])->name('employees.qualifications.destroy');
        Route::post('employees/{employee}/experiences',               [$ec,'storeExperience'])->name('employees.experiences.store');
        Route::delete('employees/{employee}/experiences/{experience}',[$ec,'destroyExperience'])->name('employees.experiences.destroy');
        Route::post('employees/{employee}/dependants',                [$ec,'storeDependant'])->name('employees.dependants.store');
        Route::delete('employees/{employee}/dependants/{dependant}',  [$ec,'destroyDependant'])->name('employees.dependants.destroy');
        Route::post('employees/{employee}/contracts',                 [$ec,'storeContract'])->name('employees.contracts.store');
        Route::post('employees/{employee}/bank-accounts',             [$ec,'storeBankAccount'])->name('employees.bank-accounts.store');
        Route::delete('employees/{employee}/bank-accounts/{bankAccount}', [$ec,'destroyBankAccount'])->name('employees.bank-accounts.destroy');
        Route::post('employees/{employee}/documents',                 [$ec,'storeDocument'])->name('employees.documents.store');
        Route::delete('employees/{employee}/documents/{document}',    [$ec,'destroyDocument'])->name('employees.documents.destroy');
    });

    // ── Roster ──────────────────────────────────────────────────
    Route::prefix('roster')->name('roster.')->middleware('module:roster')->group(function () {
        Route::resource('duty', \App\Http\Controllers\Roster\DutyRosterController::class);
        Route::resource('timetables', \App\Http\Controllers\Roster\TimetableController::class);
        Route::resource('annual-leave', \App\Http\Controllers\Roster\AnnualLeaveRosterController::class);
        Route::resource('codes', \App\Http\Controllers\Roster\RosterCodeController::class);
    });

    // ── Leave ────────────────────────────────────────────────────
    Route::prefix('leave')->name('leave.')->middleware('module:leave')->group(function () {
        Route::resource('requests', \App\Http\Controllers\Leave\LeaveRequestController::class);
        Route::resource('types', \App\Http\Controllers\Leave\LeaveTypeController::class);
        Route::resource('absence', \App\Http\Controllers\Leave\AbsenceRequestController::class);
        Route::resource('public-holidays', \App\Http\Controllers\Leave\PublicHolidayController::class);
    });

    // ── Attendance ───────────────────────────────────────────────
    Route::prefix('attendance')->name('attendance.')->middleware('module:attendance')->group(function () {
        Route::get('/', [\App\Http\Controllers\Attendance\AttendanceController::class, 'index'])->name('index');
        Route::resource('claims', \App\Http\Controllers\Attendance\AttendanceClaimController::class);
    });

    // ── Allowance ────────────────────────────────────────────────
    Route::prefix('allowance')->name('allowance.')->middleware('module:allowance')->group(function () {
        Route::resource('requests', \App\Http\Controllers\Allowance\EmpAllowanceController::class);
        Route::resource('daily', \App\Http\Controllers\Allowance\DailyAllowanceController::class);
        Route::resource('sheets', \App\Http\Controllers\Allowance\AllowanceSheetController::class);
        Route::resource('categories', \App\Http\Controllers\Allowance\AllowanceCategoryController::class);
    });

    // ── Overtime ─────────────────────────────────────────────────
    Route::prefix('overtime')->name('overtime.')->middleware('module:overtime')->group(function () {
        Route::get('/', [\App\Http\Controllers\Overtime\OvertimeController::class, 'index'])->name('index');
        Route::resource('sheets', \App\Http\Controllers\Overtime\OvertimeSheetController::class);
    });

    // ── Payroll ──────────────────────────────────────────────────
    Route::prefix('payroll')->name('payroll.')->middleware('module:payroll')->group(function () {
        Route::resource('sheets', \App\Http\Controllers\Payroll\PayrollSheetController::class);
        Route::resource('salary-adjustments', \App\Http\Controllers\Payroll\SalaryAdjustmentController::class);
        Route::get('deductions', [\App\Http\Controllers\Payroll\DeductionController::class, 'index'])->name('deductions.index');
    });

    // ── Loans ────────────────────────────────────────────────────
    Route::prefix('loans')->name('loans.')->middleware('module:loans')->group(function () {
        Route::get('/', [\App\Http\Controllers\Loan\LoanController::class, 'index'])->name('index');
        Route::resource('advances', \App\Http\Controllers\Loan\AdvanceController::class);
        Route::resource('providers', \App\Http\Controllers\Loan\LoanProviderController::class);
    });

    // ── Accounting ───────────────────────────────────────────────
    Route::prefix('accounting')->name('accounting.')->middleware('module:accounting')->group(function () {
        Route::resource('chart', \App\Http\Controllers\Accounting\ChartOfAccountController::class);
        Route::resource('journals', \App\Http\Controllers\Accounting\JournalController::class);
        Route::post('journals/{journal}/post', [\App\Http\Controllers\Accounting\JournalController::class, 'post'])->name('journals.post');
        Route::get('transactions', [\App\Http\Controllers\Accounting\TransactionController::class, 'index'])->name('transactions.index');
    });

    // ── Bills ────────────────────────────────────────────────────
    Route::prefix('bills')->name('bills.')->middleware('module:bills')->group(function () {
        Route::resource('bills', \App\Http\Controllers\Bills\BillController::class)->parameter('bills', 'bill')->names([
            'index'   => 'index',   'create'  => 'create',  'store'   => 'store',
            'show'    => 'show',    'edit'     => 'edit',    'update'  => 'update',
            'destroy' => 'destroy',
        ]);
        Route::resource('payments', \App\Http\Controllers\Bills\PaymentController::class);
        Route::resource('suppliers', \App\Http\Controllers\Bills\SupplierController::class);
    });

    // ── Procurement ──────────────────────────────────────────────
    Route::prefix('procurement')->name('procurement.')->middleware('module:procurement')->group(function () {
        Route::resource('requests', \App\Http\Controllers\Procurement\PurchaseRequestController::class);
        Route::post('requests/{request}/approve', [\App\Http\Controllers\Procurement\PurchaseRequestController::class, 'approve'])->name('requests.approve');
        Route::post('requests/{request}/reject',  [\App\Http\Controllers\Procurement\PurchaseRequestController::class, 'reject'])->name('requests.reject');
        Route::resource('orders', \App\Http\Controllers\Procurement\PurchaseOrderController::class);
    });

    // ── Assets ───────────────────────────────────────────────────
    Route::prefix('assets')->name('assets.')->middleware('module:assets')->group(function () {
        Route::resource('assets', \App\Http\Controllers\Assets\AssetController::class)->parameter('assets', 'asset')->names([
            'index'   => 'index',   'create'  => 'create',  'store'   => 'store',
            'show'    => 'show',    'edit'     => 'edit',    'update'  => 'update',
            'destroy' => 'destroy',
        ]);
        Route::resource('categories', \App\Http\Controllers\Assets\AssetCategoryController::class);
        Route::resource('locations',  \App\Http\Controllers\Assets\AssetLocationController::class);
    });

    // ── Appraisals ───────────────────────────────────────────────
    Route::prefix('appraisals')->name('appraisals.')->middleware('module:appraisals')->group(function () {
        Route::resource('scales',    \App\Http\Controllers\Appraisals\AppraisalRatingScaleController::class);
        Route::resource('templates', \App\Http\Controllers\Appraisals\DepartmentAppraisalController::class);
        Route::post('templates/{template}/kpis',           [\App\Http\Controllers\Appraisals\DepartmentAppraisalController::class, 'storeKpi'])->name('templates.kpis.store');
        Route::delete('templates/{template}/kpis/{kpi}',   [\App\Http\Controllers\Appraisals\DepartmentAppraisalController::class, 'destroyKpi'])->name('templates.kpis.destroy');
    });

    // ── Fleet ────────────────────────────────────────────────────
    Route::prefix('fleet')->name('fleet.')->middleware('module:fleet')->group(function () {
        Route::resource('vehicles',    \App\Http\Controllers\Fleet\VehicleController::class);
        Route::resource('fuel',        \App\Http\Controllers\Fleet\FuelRecordController::class)->except(['edit','update']);
        Route::resource('maintenance', \App\Http\Controllers\Fleet\MaintenanceController::class)->except(['edit','update']);
    });

    // ── Inventory ────────────────────────────────────────────────
    Route::prefix('inventory')->name('inventory.')->middleware('module:inventory')->group(function () {
        Route::resource('categories', \App\Http\Controllers\Inventory\InventoryCategoryController::class);
        Route::resource('items',      \App\Http\Controllers\Inventory\InventoryItemController::class);
        Route::resource('movements',  \App\Http\Controllers\Inventory\StockMovementController::class)->only(['index','create','store','show']);
    });

    // ── Administration ───────────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
        Route::resource('roles', \App\Http\Controllers\Admin\RoleController::class);
        Route::resource('permissions', \App\Http\Controllers\Admin\PermissionController::class);
        Route::resource('companies', \App\Http\Controllers\Admin\CompanyController::class);
    });

    // ── Settings ─────────────────────────────────────────────────
    Route::get('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'index'])->name('settings.index');
});
