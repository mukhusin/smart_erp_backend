<?php

namespace Modules\Audit\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Actions\ManageEngagements;
use Modules\Audit\Models\AuditEngagement;
use Modules\Workflow\Contracts\WorkflowProcess;

class AuditEngagementProcess implements WorkflowProcess
{
    public const KEY = 'audit-engagement';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'audit';
    }

    public function name(): string
    {
        return 'External auditor access';
    }

    public function description(): string
    {
        return 'Read-only access for an external audit firm, limited to the audited period and switched off on its end date.';
    }

    /** @param AuditEngagement $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Audit access for {$subject->auditor_name} ({$subject->firm})", 'lines' => array_values(array_filter([
            'Audits '.$subject->period_from->format('d M Y').' to '.$subject->period_to->format('d M Y'),
            'Access until '.$subject->access_until->format('d M Y').($subject->payslips ? ' · may see individual payslips' : ' · payroll totals only'),
            $subject->scope_note,
        ])), 'link' => '/audit/engagements'];
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
        app(ManageEngagements::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageEngagements::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
