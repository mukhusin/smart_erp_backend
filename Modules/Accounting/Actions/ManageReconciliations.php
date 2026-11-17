<?php

namespace Modules\Accounting\Actions;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankReconciliation;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\PostingService;
use Modules\Accounting\Workflow\BankReconciliationProcess;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageReconciliations
{
    public const MATCH_DAYS = 7;

    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting) {}

    public function create(array $data, User $by): BankReconciliation
    {
        $bank = CashBankAccount::whereNotNull('account_id')->find($data['cash_bank_account_id']);
        if (! $bank) {
            throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose a bank or cash account linked to a ledger account.']);
        }
        $open = BankReconciliation::where('cash_bank_account_id', $bank->id)->where('status', '!=', 'completed')->value('number');
        if ($open) {
            throw ValidationException::withMessages(['cash_bank_account_id' => "Finish reconciliation {$open} of {$bank->name} first."]);
        }
        $this->checkDate($bank, $data['statement_date']);

        $rec = BankReconciliation::create([
            'number' => Numbering::next(BankReconciliation::class, 'BR', $data['statement_date']), 'cash_bank_account_id' => $bank->id,
            'statement_date' => $data['statement_date'], 'statement_balance' => round((float) $data['statement_balance'], 2), 'notes' => $data['notes'] ?? null,
            'status' => 'draft', 'prepared_by' => $by->id,
        ]);
        if ($bank->feed_enabled) {
            app(\Modules\Accounting\Services\BankFeed::class)->pullInto($rec, $by->id);
        }

        return $rec;
    }

    public function update(BankReconciliation $rec, array $data): BankReconciliation
    {
        $this->editable($rec);
        $this->checkDate($rec->bank, $data['statement_date'], $rec);
        DB::transaction(function () use ($rec, $data) {
            $rec->update(['statement_date' => $data['statement_date'], 'statement_balance' => round((float) $data['statement_balance'], 2), 'notes' => $data['notes'] ?? null]);
            JournalEntryLine::where('bank_reconciliation_id', $rec->id)->whereHas('entry', fn ($q) => $q->whereDate('date', '>', $rec->statement_date))->update(['bank_reconciliation_id' => null]);
        });

        return $rec->refresh();
    }

    public function delete(BankReconciliation $rec): void
    {
        $this->editable($rec);
        DB::transaction(function () use ($rec) {
            JournalEntryLine::where('bank_reconciliation_id', $rec->id)->update(['bank_reconciliation_id' => null]);
            $rec->statementLines()->delete();
            $rec->delete();
        });
    }

    /** Ledger lines of the bank up to the statement date that are not cleared by an earlier reconciliation. */
    public function bookLines(BankReconciliation $rec): Collection
    {
        return JournalEntryLine::with('entry')->where('account_id', $rec->bank->account_id)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->whereDate('date', '<=', $rec->statement_date))
            ->where(fn ($q) => $q->whereNull('bank_reconciliation_id')->orWhere('bank_reconciliation_id', $rec->id))
            ->get()->sortBy(fn ($l) => $l->entry->date->format('Ymd').str_pad((string) $l->id, 10, '0', STR_PAD_LEFT))->values();
    }

    /** @return array{book_balance: float, statement_balance: float, uncleared_payments: float, uncleared_receipts: float, adjusted_bank: float, difference: float, cleared: int, uncleared: int, unmatched_statement: int} */
    public function summary(BankReconciliation $rec, ?Collection $lines = null): array
    {
        $lines ??= $this->bookLines($rec);
        $book = $this->bookBalance($rec->bank, $rec->statement_date->toDateString());
        $open = $lines->where('bank_reconciliation_id', null);
        $payments = round((float) $open->sum('credit'), 2);
        $receipts = round((float) $open->sum('debit'), 2);
        $adjusted = round($rec->statement_balance - $payments + $receipts, 2);

        return ['book_balance' => $book, 'statement_balance' => $rec->statement_balance, 'uncleared_payments' => $payments, 'uncleared_receipts' => $receipts,
            'adjusted_bank' => $adjusted, 'difference' => round($adjusted - $book, 2), 'cleared' => $lines->count() - $open->count(), 'uncleared' => $open->count(),
            'unmatched_statement' => $rec->statementLines()->whereNull('journal_entry_line_id')->count()];
    }

    public function bookBalance(CashBankAccount $bank, string $date): float
    {
        $row = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->where('l.account_id', $bank->account_id)
            ->whereDate('e.date', '<=', $date)->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /** @param list<int> $ids */
    public function clear(BankReconciliation $rec, array $ids, bool $cleared): void
    {
        $this->editable($rec);
        $allowed = $this->bookLines($rec)->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $ids), $allowed));
        DB::transaction(function () use ($rec, $ids, $cleared) {
            JournalEntryLine::whereIn('id', $ids)->update(['bank_reconciliation_id' => $cleared ? $rec->id : null]);
            if (! $cleared) {
                $rec->statementLines()->whereIn('journal_entry_line_id', $ids)->update(['journal_entry_line_id' => null]);
            }
        });
    }

    /**
     * @param  list<array<string, string|null>>  $rows  date, description, reference, and amount (money in positive) or money_in / money_out
     * @return array{added: int, errors: list<string>}
     */
    public function import(BankReconciliation $rec, array $rows, User $by): array
    {
        $this->editable($rec);
        $added = 0;
        $errors = [];
        DB::transaction(function () use ($rec, $rows, $by, &$added, &$errors) {
            foreach ($rows as $i => $row) {
                $n = $i + 2;
                $date = $this->parseDate($row['date'] ?? null);
                if (! $date) {
                    $errors[] = "Row {$n}: the date is not a date (use YYYY-MM-DD or DD/MM/YYYY).";

                    continue;
                }
                $amount = isset($row['amount']) && trim((string) $row['amount']) !== ''
                    ? $this->number($row['amount'])
                    : $this->number($row['money_in'] ?? null) - $this->number($row['money_out'] ?? null);
                if (round($amount, 2) == 0) {
                    $errors[] = "Row {$n}: no amount.";

                    continue;
                }
                if ($date->gt($rec->statement_date)) {
                    $errors[] = "Row {$n}: dated after the statement date.";

                    continue;
                }
                $rec->statementLines()->create(['company_id' => $rec->company_id, 'cash_bank_account_id' => $rec->cash_bank_account_id, 'date' => $date->toDateString(),
                    'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 255) ?: null, 'reference' => mb_substr(trim((string) ($row['reference'] ?? '')), 0, 100) ?: null,
                    'amount' => round($amount, 2), 'created_by' => $by->id]);
                $added++;
            }
        });

        return ['added' => $added, 'errors' => $errors];
    }

    public function removeStatementLine(BankReconciliation $rec, BankStatementLine $line): void
    {
        $this->editable($rec);
        abort_unless($line->bank_reconciliation_id === $rec->id, 404);
        $line->delete();
    }

    /** Pairs statement lines with uncleared ledger lines of the same amount near the same date, and clears reversed journals with their reversal. */
    public function autoMatch(BankReconciliation $rec): int
    {
        $this->editable($rec);
        $matched = 0;
        DB::transaction(function () use ($rec, &$matched) {
            $lines = $this->bookLines($rec);
            foreach ($lines->whereNull('bank_reconciliation_id') as $l) {
                $reversal = $l->entry->status === 'reversed' ? $lines->first(fn ($x) => $x->entry->reversal_of_id === $l->journal_entry_id && $x->bank_reconciliation_id === null) : null;
                if ($reversal && round($l->debit - $l->credit, 2) === round($reversal->credit - $reversal->debit, 2)) {
                    JournalEntryLine::whereIn('id', [$l->id, $reversal->id])->update(['bank_reconciliation_id' => $rec->id]);
                    $l->bank_reconciliation_id = $reversal->bank_reconciliation_id = $rec->id;
                }
            }
            $used = $rec->statementLines()->whereNotNull('journal_entry_line_id')->pluck('journal_entry_line_id')->all();
            $free = $lines->whereNull('bank_reconciliation_id')->reject(fn ($l) => in_array($l->id, $used, true));
            foreach ($rec->statementLines()->whereNull('journal_entry_line_id')->get() as $s) {
                $candidates = $free->filter(fn ($l) => round($l->debit - $l->credit, 2) === round($s->amount, 2) && abs($l->entry->date->diffInDays($s->date, false)) <= self::MATCH_DAYS);
                if ($candidates->isEmpty()) {
                    continue;
                }
                $best = ($s->reference ? $candidates->first(fn ($l) => str_contains(mb_strtolower($l->entry->reference_no.' '.$l->entry->description.' '.$l->description), mb_strtolower($s->reference))) : null)
                    ?? $candidates->sortBy(fn ($l) => abs($l->entry->date->diffInDays($s->date, false)))->first();
                $s->update(['journal_entry_line_id' => $best->id]);
                JournalEntryLine::where('id', $best->id)->update(['bank_reconciliation_id' => $rec->id]);
                $free = $free->reject(fn ($l) => $l->id === $best->id);
                $matched++;
            }
        });

        return $matched;
    }

    public function match(BankReconciliation $rec, BankStatementLine $s, ?int $lineId): void
    {
        $this->editable($rec);
        abort_unless($s->bank_reconciliation_id === $rec->id, 404);
        DB::transaction(function () use ($rec, $s, $lineId) {
            if ($s->journal_entry_line_id) {
                JournalEntryLine::where('id', $s->journal_entry_line_id)->update(['bank_reconciliation_id' => null]);
                $s->update(['journal_entry_line_id' => null]);
            }
            if (! $lineId) {
                return;
            }
            $line = $this->bookLines($rec)->firstWhere('id', $lineId);
            if (! $line || round($line->debit - $line->credit, 2) !== round($s->amount, 2)) {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'Choose a ledger line of this bank with the same amount.']);
            }
            if ($rec->statementLines()->where('journal_entry_line_id', $line->id)->exists()) {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'That ledger line is already matched to another statement line.']);
            }
            $s->update(['journal_entry_line_id' => $line->id]);
            $line->update(['bank_reconciliation_id' => $rec->id]);
        });
    }

    /** Books a statement line missing from the ledger (bank charges, interest, direct credit) and matches it. */
    public function adjust(BankReconciliation $rec, BankStatementLine $s, int $accountId, ?string $description, User $by): void
    {
        $this->editable($rec);
        abort_unless($s->bank_reconciliation_id === $rec->id, 404);
        if ($s->journal_entry_line_id) {
            throw ValidationException::withMessages(['line' => 'This statement line is already matched.']);
        }
        $account = Account::find($accountId);
        if (! $account || $account->id === $rec->bank->account_id) {
            throw ValidationException::withMessages(['account_id' => 'Choose the account for the other side (for example bank charges or interest income).']);
        }
        DB::transaction(function () use ($rec, $s, $account, $description, $by) {
            $text = $description ?: ($s->description ?: 'Bank statement item');
            $amount = abs($s->amount);
            $bankLine = ['account_id' => $rec->bank->account_id, $s->amount > 0 ? 'debit' : 'credit' => $amount, 'description' => $text];
            $other = ['account_id' => $account->id, $s->amount > 0 ? 'credit' : 'debit' => $amount, 'description' => $text];
            $journal = $this->posting->post($s->date->toDateString(), "{$rec->number} {$rec->bank->name}: {$text}".($s->reference ? " ({$s->reference})" : ''), [$bankLine, $other], $by, 'bank', $rec);
            $line = $journal->lines->firstWhere('account_id', $rec->bank->account_id);
            $line->update(['bank_reconciliation_id' => $rec->id]);
            $s->update(['journal_entry_line_id' => $line->id]);
        });
    }

    public function submit(BankReconciliation $rec, User $by): BankReconciliation
    {
        $this->editable($rec);
        $summary = $this->summary($rec);
        if ($summary['difference'] != 0) {
            throw ValidationException::withMessages(['difference' => 'The reconciliation does not balance: difference '.number_format($summary['difference'], 2).'. Clear the items on the statement or book the missing ones.']);
        }

        return DB::transaction(function () use ($rec, $by, $summary) {
            $rec->update(['status' => 'pending', 'submitted_at' => now(), 'book_balance' => $summary['book_balance'], 'difference' => 0, 'decision_comment' => null]);
            if (! $this->workflow->start(BankReconciliationProcess::KEY, $rec, $by)) {
                $this->approved($rec);
            }

            return $rec->refresh();
        });
    }

    public function approved(BankReconciliation $rec): void
    {
        $rec->update(['status' => 'completed', 'completed_at' => now()]);
        $rec->preparer?->notify(new Notice('Bank reconciliation approved', "{$rec->number} {$rec->bank?->name} to {$rec->statement_date->format('d M Y')} is completed.", "/accounting/reconciliations/{$rec->id}", 'bank'));
    }

    public function rejected(BankReconciliation $rec, ?string $comment, bool $withdrawn): void
    {
        $rec->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
        if (! $withdrawn) {
            $rec->preparer?->notify(new Notice('Bank reconciliation returned', "{$rec->number} was returned: {$comment}", "/accounting/reconciliations/{$rec->id}", 'bank'));
        }
    }

    public function withdraw(BankReconciliation $rec, User $by): BankReconciliation
    {
        if ($rec->status !== 'pending' || $rec->prepared_by !== $by->id) {
            throw ValidationException::withMessages(['reconciliation' => 'Only your reconciliation waiting for approval can be withdrawn.']);
        }
        $instance = $rec->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($rec, null, true);

        return $rec->refresh();
    }

    /** Opens the latest completed reconciliation of a bank again (to correct it). */
    public function reopen(BankReconciliation $rec): BankReconciliation
    {
        $latest = BankReconciliation::where('cash_bank_account_id', $rec->cash_bank_account_id)->where('status', 'completed')->orderByDesc('statement_date')->orderByDesc('id')->first();
        if ($rec->status !== 'completed' || $latest?->id !== $rec->id || BankReconciliation::where('cash_bank_account_id', $rec->cash_bank_account_id)->where('status', '!=', 'completed')->exists()) {
            throw ValidationException::withMessages(['reconciliation' => 'Only the latest completed reconciliation of a bank, with no other open, can be opened again.']);
        }
        $rec->update(['status' => 'draft', 'completed_at' => null]);

        return $rec->refresh();
    }

    private function checkDate(CashBankAccount $bank, string $date, ?BankReconciliation $except = null): void
    {
        $last = BankReconciliation::where('cash_bank_account_id', $bank->id)->where('status', 'completed')->when($except, fn ($q) => $q->where('id', '!=', $except->id))->max('statement_date');
        if ($last && Carbon::parse($date)->lte(Carbon::parse($last))) {
            throw ValidationException::withMessages(['statement_date' => 'The statement date must be after the last reconciliation ('.Carbon::parse($last)->format('d M Y').').']);
        }
    }

    private function editable(BankReconciliation $rec): void
    {
        if (! $rec->editable()) {
            throw ValidationException::withMessages(['reconciliation' => 'Only a draft or returned reconciliation can be changed.']);
        }
    }

    private function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd M Y', 'd-M-Y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, $value);
                if ($d && $d->format($format) === $value) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function number(mixed $value): float
    {
        $v = trim((string) $value);
        if ($v === '') {
            return 0;
        }
        $negative = str_starts_with($v, '(') && str_ends_with($v, ')');

        return ($negative ? -1 : 1) * (float) preg_replace('/[^0-9.\-]/', '', $v);
    }
}
