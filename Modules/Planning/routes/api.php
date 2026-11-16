<?php

use Illuminate\Support\Facades\Route;
use Modules\Planning\Http\Controllers\Api\AnnualPlanController;
use Modules\Planning\Http\Controllers\Api\DepartmentPlanController;
use Modules\Planning\Http\Controllers\Api\PlanningDashboardController;
use Modules\Planning\Http\Controllers\Api\StrategicPlanController;
use Modules\Planning\Http\Controllers\Api\SubActivityController;

Route::prefix('planning')->middleware(['auth:sanctum', 'module:planning'])->name('planning.')->group(function () {
    Route::get('dashboard', PlanningDashboardController::class)->name('dashboard');
    Route::get('strategic-plans', [StrategicPlanController::class, 'index'])->name('strategic.index');
    Route::post('strategic-plans', [StrategicPlanController::class, 'store'])->name('strategic.store');
    Route::get('strategic-plans/{plan}', [StrategicPlanController::class, 'show'])->name('strategic.show');
    Route::put('strategic-plans/{plan}', [StrategicPlanController::class, 'update'])->name('strategic.update');
    Route::delete('strategic-plans/{plan}', [StrategicPlanController::class, 'destroy'])->name('strategic.destroy');
    Route::post('strategic-plans/{plan}/objectives', [StrategicPlanController::class, 'storeObjective'])->name('objectives.store');
    Route::put('strategic-plans/{plan}/objectives/{objective}', [StrategicPlanController::class, 'updateObjective'])->name('objectives.update');
    Route::delete('strategic-plans/{plan}/objectives/{objective}', [StrategicPlanController::class, 'destroyObjective'])->name('objectives.destroy');

    Route::get('annual-plans', [AnnualPlanController::class, 'index'])->name('annual.index');
    Route::post('annual-plans', [AnnualPlanController::class, 'store'])->name('annual.store');
    Route::get('annual-plans/{plan}', [AnnualPlanController::class, 'show'])->name('annual.show');
    Route::put('annual-plans/{plan}', [AnnualPlanController::class, 'update'])->name('annual.update');
    Route::delete('annual-plans/{plan}', [AnnualPlanController::class, 'destroy'])->name('annual.destroy');
    Route::post('annual-plans/{plan}/open', [AnnualPlanController::class, 'open'])->name('annual.open');
    Route::post('annual-plans/{plan}/close', [AnnualPlanController::class, 'close'])->name('annual.close');

    Route::get('annual-plans/{plan}/departments/{department}', [DepartmentPlanController::class, 'show'])->name('department.show');
    Route::post('annual-plans/{plan}/departments/{department}/activities', [DepartmentPlanController::class, 'storeActivity'])->name('department.activities.store');
    Route::put('annual-plans/{plan}/departments/{department}/activities/{activity}', [DepartmentPlanController::class, 'updateActivity'])->name('department.activities.update');
    Route::delete('annual-plans/{plan}/departments/{department}/activities/{activity}', [DepartmentPlanController::class, 'destroyActivity'])->name('department.activities.destroy');
    Route::post('annual-plans/{plan}/departments/{department}/activities/{activity}/sub-activities', [SubActivityController::class, 'store'])->name('sub-activities.store');
    Route::put('annual-plans/{plan}/departments/{department}/activities/{activity}/sub-activities/{sub}', [SubActivityController::class, 'update'])->name('sub-activities.update');
    Route::delete('annual-plans/{plan}/departments/{department}/activities/{activity}/sub-activities/{sub}', [SubActivityController::class, 'destroy'])->name('sub-activities.destroy');
    Route::post('annual-plans/{plan}/departments/{department}/{action}', [DepartmentPlanController::class, 'action'])->whereIn('action', ['submit', 'withdraw', 'reopen'])->name('department.action');
});
