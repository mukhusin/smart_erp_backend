<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Events\JournalPosted;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;

class PostingService
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    public function validateLines(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => round((float) ($l['debit'] ?? 0), 2) != 0 || round((float) ($l['credit'] ?? 0), 2) != 0));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal needs at least two lines with amounts.']);
        }
        $accounts = Account::whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');
        foreach ($lines as $i => $l) {
            $debit = round((float) ($l['debit'] ?? 0), 2);
            $credit = round((float) ($l['credit'] ?? 0), 2);
            if (($debit != 0 && $credit != 0) || $debit < 0 || $credit < 0) {
                throw ValidationException::withMessages(["lines.$i" => 'Line '.($i + 1).': enter either a debit or a credit, not both, and no negative amounts.']);
            }
            $account = $accounts[$l['account_id']] ?? null;
            if (! $account || ! $account->is_active || ! $account->is_postable) {
                throw ValidationException::withMessages(["lines.$i" => 'Line '.($i + 1).': choose an active account that takes postings (not a heading).']);
            }
        }
        $debits = round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)), 2);
        $credits = round(array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $lines)), 2);
        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => 'Debits ('.number_format($debits, 2).') and credits ('.number_format($credits, 2).') must be equal.']);
        }

        return $lines;
    }

    public function post(string $date, string $description, array $lines, User $by, string $type = 'manual', ?Model $source = null, ?JournalEntry $entry = null): JournalEntry
    {
        $lines = $this->validateLines($lines);
        $period = $this->calendar->openPeriodFor($date);

        return DB::transaction(function () use ($date, $description, $lines, $by, $type, $source, $entry, $period) {
            $total = round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)), 2);
            $entry ??= new JournalEntry(['created_by' => $by->id, 'entry_type' => $type]);
            $entry->fill([
                'date' => $date, 'fiscal_period_id' => $period->id, 'description' => $description, 'total' => $total, 'status' => 'posted',
                'posted_at' => now(), 'posted_by' => $by->id,
                'reference_no' => $entry->reference_no ?: $this->nextNumber($date),
            ]);
            if ($source) {
                $entry->source()->associate($source);
            }
            $entry->save();
            $entry->lines()->delete();
            foreach ($lines as $i => $l) {
                $entry->lines()->create([
                    'line_no' => $i + 1, 'account_id' => $l['account_id'], 'debit' => round((float) ($l['debit'] ?? 0), 2), 'credit' => round((float) ($l['credit'] ?? 0), 2),
                    'description' => $l['description'] ?? null, 'department_id' => $l['department_id'] ?? null, 'fund_source_id' => $l['fund_source_id'] ?? null,
                    'task_activity_id' => $l['task_activity_id'] ?? null, 'project_id' => $l['project_id'] ?? null, 'budget_item_id' => $l['budget_item_id'] ?? null,
                ]);
            }

            event(new JournalPosted($entry->load('lines'), $by));

            return $entry;
        });
    }

    public function reverse(JournalEntry $entry, string $date, User $by, ?string $reason = null): JournalEntry
    {
        if ($entry->status !== 'posted') {
            throw ValidationException::withMessages(['entry' => 'Only a posted journal can be reversed.']);
        }

        return DB::transaction(function () use ($entry, $date, $by, $reason) {
            $lines = $entry->lines->map(fn ($l) => ['account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'description' => $l->description,
                'department_id' => $l->department_id, 'fund_source_id' => $l->fund_source_id, 'task_activity_id' => $l->task_activity_id, 'project_id' => $l->project_id, 'budget_item_id' => $l->budget_item_id])->all();
            $reversal = $this->post($date, 'Reversal of '.$entry->reference_no.($reason ? ': '.$reason : ''), $lines, $by, 'reversal', $entry->source);
            $reversal->update(['reversal_of_id' => $entry->id]);
            $entry->update(['status' => 'reversed']);

            return $reversal;
        });
    }

    public function nextNumber(string $date): string
    {
        $prefix = 'JV-'.Carbon::parse($date)->format('Y').'-';
        $last = JournalEntry::where('reference_no', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('reference_no')->value('reference_no');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
