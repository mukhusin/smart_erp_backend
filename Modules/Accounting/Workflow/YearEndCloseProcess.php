<?php

namespace Modules\Accounting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Services\YearEndClose;
use Modules\Workflow\Contracts\WorkflowProcess;

class YearEndCloseProcess implements WorkflowProcess
{
    public const KEY = 'year-end-close';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'accounting';
    }

    public function name(): string
    {
        return 'Year-end close';
    }

    public function description(): string
    {
        return 'Closing a fiscal year: income and expenses go to retained earnings, the year is locked and its statements are kept as approved.';
    }

    /** @param FiscalYear $subject */
    public function summary(Model $subject): array
    {
        $p = app(YearEndClose::class)->preview($subject);

        return [
            'title' => "Close fiscal year {$subject->name}",
            'lines' => [
                $subject->start_date->format('d M Y').' to '.$subject->end_date->format('d M Y'),
                'Income '.number_format($p['revenue'], 2).' · expenses '.number_format($p['expense'], 2).' · result '.number_format($p['result'], 2),
                'Result goes to '.($p['retained_account'] ?? '—'),
            ],
            'link' => '/accounting/year-end',
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
        app(YearEndClose::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(YearEndClose::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
