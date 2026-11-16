<?php

namespace Modules\Appraisals\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Appraisals\Actions\ManageAppraisals;
use Modules\Appraisals\Models\Appraisal;
use Modules\Appraisals\Support\AppraisalScoring;
use Modules\Workflow\Contracts\WorkflowProcess;

class AppraisalProcess implements WorkflowProcess
{
    public const KEY = 'appraisal';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'appraisals';
    }

    public function name(): string
    {
        return 'Performance appraisal';
    }

    public function description(): string
    {
        return 'An employee\'s self-assessment, then the supervisor\'s and head of department\'s ratings and HR moderation. The employee then acknowledges it.';
    }

    /** @param Appraisal $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing('cycle', 'scores', 'employee');
        $self = app(AppraisalScoring::class)->final($subject, app(AppraisalScoring::class)->partScores($subject, 'self'));

        return [
            'title' => 'Appraisal: '.$subject->employee?->full_name,
            'lines' => array_values(array_filter([
                $subject->cycle?->name,
                $self !== null ? "Self-assessment: {$self}%" : null,
                'Open the appraisal to rate it.',
            ])),
            'link' => "/appraisals/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageAppraisals::class)->finalise($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageAppraisals::class)->returned($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
