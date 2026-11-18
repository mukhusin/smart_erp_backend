<?php

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\Api\ContractController;
use Modules\Procurement\Http\Controllers\Api\OrderController;
use Modules\Procurement\Http\Controllers\Api\PlanController;
use Modules\Procurement\Http\Controllers\Api\QuotationController;
use Modules\Procurement\Http\Controllers\Api\ReceiptController;
use Modules\Procurement\Http\Controllers\Api\RequisitionController;
use Modules\Procurement\Http\Controllers\Api\SupplierController;
use Modules\Procurement\Http\Controllers\Api\TenderController;

Route::prefix('procurement')->middleware(['auth:sanctum', 'module:procurement'])->name('procurement.')->group(function () {
    Route::get('lookups', [SupplierController::class, 'lookups'])->name('lookups');
    Route::get('form-lookups', [RequisitionController::class, 'lookups'])->name('form-lookups');
    Route::get('budget-lines', [RequisitionController::class, 'budgetLines'])->name('budget-lines');
    Route::get('settings', [RequisitionController::class, 'settings'])->name('settings.show');
    Route::put('settings', [RequisitionController::class, 'updateSettings'])->name('settings.update');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plan-lines', [PlanController::class, 'lines'])->name('plans.lines');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
    Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::post('plans/{plan}/submit', [PlanController::class, 'submit'])->name('plans.submit');
    Route::post('plans/{plan}/withdraw', [PlanController::class, 'withdraw'])->name('plans.withdraw');
    Route::post('plans/{plan}/revise', [PlanController::class, 'revise'])->name('plans.revise');

    Route::get('requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::post('requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::get('requisitions/{requisition}', [RequisitionController::class, 'show'])->name('requisitions.show');
    Route::put('requisitions/{requisition}', [RequisitionController::class, 'update'])->name('requisitions.update');
    Route::post('requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');
    Route::post('requisitions/{requisition}/withdraw', [RequisitionController::class, 'withdraw'])->name('requisitions.withdraw');
    Route::post('requisitions/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('requisitions.cancel');
    Route::post('requisitions/{requisition}/rfqs', [QuotationController::class, 'store'])->name('rfqs.store');
    Route::post('requisitions/{requisition}/orders', [OrderController::class, 'store'])->name('orders.store');

    Route::get('methods', [TenderController::class, 'methods'])->name('methods.index');
    Route::post('methods', [TenderController::class, 'storeMethod'])->name('methods.store');
    Route::put('methods/{method}', [TenderController::class, 'updateMethod'])->name('methods.update');
    Route::post('requisitions/{requisition}/tenders', [TenderController::class, 'store'])->name('tenders.store');
    Route::get('tender-lookups', [TenderController::class, 'lookups'])->name('tenders.lookups');
    Route::get('tenders', [TenderController::class, 'index'])->name('tenders.index');
    Route::get('tenders/{tender}', [TenderController::class, 'show'])->name('tenders.show');
    Route::put('tenders/{tender}', [TenderController::class, 'update'])->name('tenders.update');
    Route::post('tenders/{tender}/submit', [TenderController::class, 'submit'])->name('tenders.submit');
    Route::post('tenders/{tender}/clarifications', [TenderController::class, 'clarify'])->name('tenders.clarify');
    Route::post('tenders/{tender}/bids', [TenderController::class, 'recordBid'])->name('tenders.bids.store');
    Route::get('tenders/{tender}/bids/{bid}/file', [TenderController::class, 'bidFile'])->name('tenders.bids.file');
    Route::post('tenders/{tender}/bids/{bid}/scores', [TenderController::class, 'score'])->name('tenders.bids.score');
    Route::post('tenders/{tender}/members', [TenderController::class, 'addMember'])->name('tenders.members.store');
    Route::delete('tenders/{tender}/members/{member}', [TenderController::class, 'removeMember'])->name('tenders.members.destroy');
    Route::post('tenders/{tender}/declare', [TenderController::class, 'declare'])->name('tenders.declare');
    Route::post('tenders/{tender}/open', [TenderController::class, 'open'])->name('tenders.open');
    Route::post('tenders/{tender}/evaluation', [TenderController::class, 'submitEvaluation'])->name('tenders.evaluation');
    Route::post('tenders/{tender}/award', [TenderController::class, 'award'])->name('tenders.award');
    Route::post('tenders/{tender}/end-standstill', [TenderController::class, 'endStandstill'])->name('tenders.end-standstill');
    Route::post('tenders/{tender}/awards/{award}/respond', [TenderController::class, 'respond'])->name('tenders.awards.respond');
    Route::post('tenders/{tender}/awards/{award}/contract', [TenderController::class, 'contract'])->name('tenders.awards.contract');
    Route::post('tenders/{tender}/complaints', [TenderController::class, 'complaint'])->name('tenders.complaints.store');
    Route::post('tenders/{tender}/complaints/{complaint}/decide', [TenderController::class, 'decideComplaint'])->name('tenders.complaints.decide');
    Route::post('tenders/{tender}/cancel', [TenderController::class, 'cancel'])->name('tenders.cancel');

    Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
    Route::get('contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
    Route::put('contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
    Route::post('contracts/{contract}/submit', [ContractController::class, 'submit'])->name('contracts.submit');
    Route::post('contracts/{contract}/withdraw', [ContractController::class, 'withdraw'])->name('contracts.withdraw');
    Route::post('contracts/{contract}/orders', [ContractController::class, 'order'])->name('contracts.orders.store');
    Route::post('contracts/{contract}/certificates', [ContractController::class, 'certificate'])->name('contracts.certificates.store');
    Route::post('contracts/{contract}/variations', [ContractController::class, 'variation'])->name('contracts.variations.store');
    Route::post('contracts/{contract}/milestones/{milestone}/pay', [ContractController::class, 'payMilestone'])->name('contracts.milestones.pay');
    Route::post('contracts/{contract}/progress', [ContractController::class, 'progress'])->name('contracts.progress.store');
    Route::post('contracts/{contract}/close', [ContractController::class, 'close'])->name('contracts.close');
    Route::post('certificates/{certificate}/withdraw', [ContractController::class, 'withdrawCertificate'])->name('certificates.withdraw');
    Route::get('certificates/{certificate}/file', [ContractController::class, 'certificateFile'])->name('certificates.file');
    Route::post('variations/{variation}/withdraw', [ContractController::class, 'withdrawVariation'])->name('variations.withdraw');

    Route::get('rfqs', [QuotationController::class, 'index'])->name('rfqs.index');
    Route::get('rfqs/{rfq}', [QuotationController::class, 'show'])->name('rfqs.show');
    Route::post('rfqs/{rfq}/quotations', [QuotationController::class, 'record'])->name('rfqs.record');
    Route::get('rfqs/{rfq}/quotations/{quotation}/file', [QuotationController::class, 'file'])->name('rfqs.file');
    Route::post('rfqs/{rfq}/award', [QuotationController::class, 'award'])->name('rfqs.award');
    Route::post('rfqs/{rfq}/cancel', [QuotationController::class, 'cancel'])->name('rfqs.cancel');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('orders/{order}/pdf', [OrderController::class, 'pdf'])->name('orders.pdf');
    Route::post('orders/{order}/submit', [OrderController::class, 'submit'])->name('orders.submit');
    Route::post('orders/{order}/withdraw', [OrderController::class, 'withdraw'])->name('orders.withdraw');
    Route::post('orders/{order}/acknowledge', [OrderController::class, 'acknowledge'])->name('orders.acknowledge');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{order}/receipts', [ReceiptController::class, 'store'])->name('receipts.store');

    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::post('receipts/{receipt}/submit', [ReceiptController::class, 'submit'])->name('receipts.submit');
    Route::post('receipts/{receipt}/withdraw', [ReceiptController::class, 'withdraw'])->name('receipts.withdraw');
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel'])->name('receipts.cancel');
    Route::put('receipts/{receipt}/invoice', [ReceiptController::class, 'invoice'])->name('receipts.invoice');
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::put('suppliers/{supplier}/blacklist', [SupplierController::class, 'blacklist'])->name('suppliers.blacklist');
    Route::post('suppliers/{supplier}/bank-accounts', [SupplierController::class, 'storeBank'])->name('suppliers.banks.store');
    Route::post('suppliers/{supplier}/bank-accounts/{account}/withdraw', [SupplierController::class, 'withdrawBank'])->name('suppliers.banks.withdraw');
    Route::post('suppliers/{supplier}/bank-accounts/{account}/disable', [SupplierController::class, 'disableBank'])->name('suppliers.banks.disable');
    Route::post('suppliers/{supplier}/documents', [SupplierController::class, 'storeDocument'])->name('suppliers.documents.store');
    Route::get('suppliers/{supplier}/documents/{document}', [SupplierController::class, 'file'])->name('suppliers.documents.file');
    Route::delete('suppliers/{supplier}/documents/{document}', [SupplierController::class, 'destroyDocument'])->name('suppliers.documents.destroy');
});
