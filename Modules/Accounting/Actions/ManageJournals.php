<?php

namespace Modules\Accounting\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Accounting\Workflow\ManualJournalProcess;
use Modules\Core\Notifications\Notice;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageJournals
{
    public function __construct(private readonly PostingService $posting, private readonly FiscalCalendar $calendar, private readonly WorkflowEngine $workflow) {}

    public function save(array $data, User $by, ?JournalEntry $entry = null): JournalEntry
    {
        if ($entry && ! in_array($entry->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['entry' => 'Only a draft or returned journal can be changed.']);
        }
        $this->calendar->periodFor($data['date']);

        return DB::transaction(function () use ($data, $by, $entry) {
            $entry ??= new JournalEntry(['created_by' => $by->id, 'entry_type' => 'manual', 'status' => 'draft']);
            $lines = array_values($data['lines']);
            $adjustment = $data['adjustment'] ?? null;
            $entry->fill(['date' => $data['date'], 'description' => $data['description'], 'adjustment' => $adjustment, 'reverse_on' => $adjustment ? ($data['reverse_on'] ?? null) : null,
                'total' => round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)), 2)])->save();
            $entry->lines()->delete();
            foreach ($lines as $i => $l) {
                $entry->lines()->create([
                    'line_no' => $i + 1, 'account_id' => $l['account_id'], 'debit' => round((float) ($l['debit'] ?? 0), 2), 'credit' => round((float) ($l['credit'] ?? 0), 2),
                    'description' => $l['description'] ?? null, 'department_id' => $l['department_id'] ?? null, 'fund_source_id' => $l['fund_source_id'] ?? null,
                    'task_activity_id' => $l['task_activity_id'] ?? null, 'budget_item_id' => $l['budget_item_id'] ?? null,
                ]);
            }

            return $entry->load('lines');
        });
    }

    public function submit(JournalEntry $entry, User $by): JournalEntry
    {
        if (! in_array($entry->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['entry' => 'This journal is already '.strtolower(JournalEntry::STATUS_LABELS[$entry->status]).'.']);
        }
        $this->posting->validateLines($this->lines($entry));
        $this->calendar->openPeriodFor($entry->date->toDateString());

        return DB::transaction(function () use ($entry, $by) {
            $entry->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(ManualJournalProcess::KEY, $entry, $by)) {
                $this->post($entry);
            }

            return $entry->refresh();
        });
    }

    public function post(JournalEntry $entry): void
    {
        $by = $entry->creator ?? auth()->user();
        $this->posting->post($entry->date->toDateString(), $entry->description, $this->lines($entry), $by, 'manual', null, $entry);
        $entry->creator?->notify(new Notice('Journal posted', "Journal {$entry->refresh()->reference_no} is approved and posted.", "/accounting/journals/{$entry->id}", 'journal-check'));
        if ($entry->reverse_on && $entry->reverse_on->lte(today())) {
            $this->reverseDue();
        }
    }

    /**
     * Reverses posted adjusting journals whose reversal date has come.
     *
     * @return array{reversed: int, failed: list<string>}
     */
    public function reverseDue(): array
    {
        $done = 0;
        $failed = [];
        $due = JournalEntry::where('status', 'posted')->whereNotNull('adjustment')->whereNotNull('reverse_on')->whereDate('reverse_on', '<=', today())
            ->whereDoesntHave('reversedBy')->with(['lines', 'creator'])->orderBy('reverse_on')->get();
        foreach ($due as $entry) {
            try {
                $by = $entry->creator ?? auth()->user();
                $reversal = $this->posting->reverse($entry, $entry->reverse_on->toDateString(), $by, 'automatic reversal of '.strtolower(JournalEntry::ADJUSTMENTS[$entry->adjustment] ?? 'adjustment'));
                $reversal->update(['adjustment' => $entry->adjustment]);
                $done++;
            } catch (\Throwable $e) {
                $failed[] = "{$entry->reference_no}: {$e->getMessage()}";
            }
        }

        return ['reversed' => $done, 'failed' => $failed];
    }

    public function rejected(JournalEntry $entry, ?string $comment, bool $withdrawn): void
    {
        $entry->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
        if (! $withdrawn) {
            $entry->creator?->notify(new Notice('Journal returned', "Your journal \"{$entry->description}\" was returned: {$comment}", "/accounting/journals/{$entry->id}", 'journal-x'));
        }
    }

    public function withdraw(JournalEntry $entry, User $by): JournalEntry
    {
        if ($entry->status !== 'pending') {
            throw ValidationException::withMessages(['entry' => 'Only a journal waiting for approval can be withdrawn.']);
        }
        $instance = $entry->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $entry->update(['status' => 'draft']);

        return $entry->refresh();
    }

    public function delete(JournalEntry $entry): void
    {
        if (! in_array($entry->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['entry' => 'Only a draft journal can be deleted; reverse a posted one.']);
        }
        $entry->delete();
    }

    private function lines(JournalEntry $entry): array
    {
        return $entry->lines()->get()->map(fn ($l) => $l->only(['account_id', 'debit', 'credit', 'description', 'department_id', 'fund_source_id', 'task_activity_id', 'project_id', 'budget_item_id']))->all();
    }
}
