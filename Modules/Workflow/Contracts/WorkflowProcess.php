<?php

namespace Modules\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A process that can need approval (user access change, leave request, payroll sheet...).
 * Each module registers its processes in WorkflowCatalog.
 */
interface WorkflowProcess
{
    /** Stable key, e.g. "user-access-change". */
    public function key(): string;

    /** ERP module (erp_modules.slug) the process belongs to; the process is offered only when the module is enabled. */
    public function module(): string;

    public function name(): string;

    public function description(): string;

    /**
     * What approvers see in their inbox.
     *
     * @return array{title: string, lines: list<string>, link: string|null}
     */
    public function summary(Model $subject): array;

    /** Branch and department of the request, for branch-specific workflows and department-head approvers. */
    public function branchId(Model $subject): ?int;

    public function departmentId(Model $subject): ?int;

    /** The employee the request is about (for supervisor approvers), or null. */
    public function employeeId(Model $subject): ?int;

    /** Called when the last step approves. */
    public function onApproved(Model $subject): void;

    /** Called when a step rejects (or the requester cancels). */
    public function onRejected(Model $subject, ?string $comment): void;
}
