<?php

namespace Modules\Accounting\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Actions\ManageJournals;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ManualJournalProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'manual-journal';

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
        return 'Manual journal';
    }

    public function description(): string
    {
        return 'A journal entered by hand (adjustments, corrections, opening balances). Posted to the ledger when approved.';
    }

    /** @param JournalEntry $subject */
    public function summary(Model $subject): array
    {
        $lines = $subject->lines()->with('account')->get();

        return [
            'title' => 'Journal: '.$subject->description,
            'lines' => array_values(array_filter([
                $subject->date?->format('d M Y').' · '.number_format((float) $subject->total, 2),
                ...$lines->take(10)->map(fn ($l) => ($l->account?->label() ?? 'Account').': '.($l->debit > 0 ? 'Dr '.number_format($l->debit, 2) : 'Cr '.number_format($l->credit, 2)))->all(),
                $lines->count() > 10 ? '… and '.($lines->count() - 10).' more lines' : null,
            ])),
            'link' => "/accounting/journals/{$subject->id}",
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
        app(ManageJournals::class)->post($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageJournals::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->lines()->sum('debit');
    }
}
