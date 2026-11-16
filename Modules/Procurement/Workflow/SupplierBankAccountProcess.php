<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageSuppliers;
use Modules\Procurement\Models\SupplierBankAccount;
use Modules\Workflow\Contracts\WorkflowProcess;

class SupplierBankAccountProcess implements WorkflowProcess
{
    public const KEY = 'supplier-bank-account';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'procurement';
    }

    public function name(): string
    {
        return 'Supplier bank account';
    }

    public function description(): string
    {
        return 'A new or changed supplier bank account is checked by a second person before money can be paid to it.';
    }

    /** @param SupplierBankAccount $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing('supplier');

        return [
            'title' => "Bank account for {$subject->supplier?->name}",
            'lines' => ["{$subject->bank_name}".($subject->branch ? ", {$subject->branch}" : ''), "Account: {$subject->account_number} ({$subject->account_name})", 'TIN: '.($subject->supplier?->tin ?? '—')],
            'link' => "/procurement/suppliers/{$subject->supplier_id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return null;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageSuppliers::class)->bankApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageSuppliers::class)->bankRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
