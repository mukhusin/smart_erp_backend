<?php

use Illuminate\Support\Facades\Route;
use Modules\Workflow\Http\Controllers\Api\ApprovalController;
use Modules\Workflow\Http\Controllers\Api\WorkflowSetupController;

// Setting up workflows: company admin.
Route::prefix('admin/workflows')->middleware(['auth:sanctum', 'module:admin', 'can:admin.workflow.manage'])->name('admin.workflows.')->group(function () {
    Route::get('/', [WorkflowSetupController::class, 'index'])->name('index');
    Route::put('{process}', [WorkflowSetupController::class, 'save'])->name('save');
});

// Approving: any company user (who may act is decided per request by the engine).
Route::prefix('workflow')->middleware('auth:sanctum')->name('workflow.')->group(function () {
    Route::get('inbox', [ApprovalController::class, 'inbox'])->name('inbox');
    Route::get('inbox/count', [ApprovalController::class, 'count'])->name('inbox.count');
    Route::get('my-requests', [ApprovalController::class, 'mine'])->name('mine');
    Route::get('requests/{instance}', [ApprovalController::class, 'show'])->name('show');
    Route::post('requests/{instance}/approve', [ApprovalController::class, 'approve'])->name('approve');
    Route::post('requests/{instance}/reject', [ApprovalController::class, 'reject'])->name('reject');
    Route::post('requests/{instance}/send-back', [ApprovalController::class, 'sendBack'])->name('send-back');
    Route::post('requests/{instance}/cancel', [ApprovalController::class, 'cancel'])->name('cancel');
});
