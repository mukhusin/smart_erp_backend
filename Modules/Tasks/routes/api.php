<?php

use Illuminate\Support\Facades\Route;
use Modules\Tasks\Http\Controllers\Api\TaskActivityController;
use Modules\Tasks\Http\Controllers\Api\TaskController;

Route::middleware(['auth:sanctum', 'module:tasks', 'can:tasks.use'])->name('tasks.')->group(function () {
    Route::get('tasks/summary', [TaskController::class, 'summary'])->name('summary');
    Route::get('tasks', [TaskController::class, 'index'])->name('index');
    Route::post('tasks', [TaskController::class, 'store'])->name('store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('show');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('update');
    Route::put('tasks/{task}/checklist', [TaskController::class, 'checklist'])->name('checklist');
    Route::post('tasks/{task}/updates', [TaskController::class, 'report'])->name('report');
    Route::post('tasks/{task}/updates/{update}/withdraw', [TaskController::class, 'withdrawUpdate'])->name('withdraw-update');
    Route::post('tasks/{task}/comments', [TaskController::class, 'comment'])->name('comment');
    Route::post('tasks/{task}/{action}', [TaskController::class, 'action'])->whereIn('action', ['start', 'complete', 'review', 'cancel', 'reopen'])->name('action');

    Route::get('task-activities', [TaskActivityController::class, 'index'])->name('activities.index');
    Route::post('task-activities', [TaskActivityController::class, 'store'])->name('activities.store');
    Route::put('task-activities/{activity}', [TaskActivityController::class, 'update'])->name('activities.update');
    Route::delete('task-activities/{activity}', [TaskActivityController::class, 'destroy'])->name('activities.destroy');
});
