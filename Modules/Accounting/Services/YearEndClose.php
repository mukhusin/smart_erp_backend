<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use Modules\Core\Notifications\Notice;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FiscalYearStatement;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OpeningBalance;
use Modules\Accounting\Workflow\YearEndCloseProcess;
use Modules\Reporting\Support\FinanceReports;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Closes a fiscal year: income and expense accounts are closed to the retained earnings (accumulated fund) account by
 * one closing journal, every period is locked, balance sheet accounts carry forward as next year's opening balances,
 * and the year's statements are kept exactly as approved.
 */
class YearEndClose
{
    public const STATEMENTS = ['income-statement', 'financial-position', 'trial-balance'];

    public function __construct(private readonly PostingService $posting, private readonly FiscalCalendar $calendar, private readonly WorkflowEngine $workflow) {}

    /** @return list<array{account_id: int, fund_source_id: int|null, department_id: int|null, debit: float, credit: float}> */
    public function closingLines(FiscalYear $year): array
    {
        $rows = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_type', '!=', 'closing')
            ->whereBetween('e.date', [$year->start_date->toDateString(), $year->end_date->toDateString()])->whereIn('a.type', ['revenue', 'expense'])
            ->groupBy('l.account_id', 'l.fund_source_id', 'l.department_id')->selectRaw('l.account_id, l.fund_source_id, l.department_id, SUM(l.debit) - SUM(l.credit) as net')->get();

        return $rows->filter(fn ($r) => round((float) $r->net, 2) != 0)->map(fn ($r) => ['account_id' => (int) $r->account_id, 'fund_source_id' => $r->fund_source_id ? (int) $r->fund_source_id : null,
            'department_id' => $r->department_id ? (int) $r->department_id : null, 'debit' => $r->net < 0 ? round(-$r->net, 2) : 0.0, 'credit' => $r->net > 0 ? round((float) $r->net, 2) : 0.0])->values()->all();
    }

    /** @return array{checks: list<array{key: string, label: string, ok: bool, detail: string|null}>, revenue: float, expense: float, result: float, retained_account: string|null, next_year: string|null, can_close: bool} */
    public function preview(FiscalYear $year): array
    {
        $settings = AccountingSetting::current();
        $retained = $settings->retained_earnings_account_id ? Account::find($settings->retained_earnings_account_id) : null;
        $earlier = FiscalYear::whereDate('start_date', '<', $year->start_date)->where('status', '!=', 'closed')->orderBy('start_date')->first();
        $from = $year->start_date->toDateString();
        $to = $year->end_date->toDateString();
        $journals = JournalEntry::whereIn('status', ['draft', 'pending', 'rejected'])->whereBetween('date', [$from, $to])->count();
        $opening = OpeningBalance::whereIn('status', ['draft', 'pending', 'rejected'])->count();
        $next = FiscalYear::whereDate('start_date', $year->end_date->copy()->addDay()->toDateString())->first();
        $lines = collect($this->closingLines($year));
        $accounts = Account::whereIn('id', $lines->pluck('account_id'))->pluck('type', 'id');
        $revenue = round($lines->filter(fn ($l) => $accounts[$l['account_id']] === 'revenue')->sum(fn ($l) => $l['debit'] - $l['credit']), 2);
        $expense = round($lines->filter(fn ($l) => $accounts[$l['account_id']] === 'expense')->sum(fn ($l) => $l['credit'] - $l['debit']), 2);
        $checks = [
            ['key' => 'earlier', 'label' => 'Earlier years are closed', 'ok' => ! $earlier, 'detail' => $earlier ? "Close {$earlier->name} first." : null],
            ['key' => 'journals', 'label' => 'No journals waiting in this year', 'ok' => $journals === 0, 'detail' => $journals ? "{$journals} journal(s) are draft, returned or waiting for approval; post or delete them." : null],
            ['key' => 'opening', 'label' => 'Opening balances finished', 'ok' => $opening === 0, 'detail' => $opening ? "{$opening} opening balance batch(es) are not approved yet." : null],
            ['key' => 'retained', 'label' => 'Retained earnings account set', 'ok' => $retained && $retained->type === 'equity' && $retained->is_postable && $retained->is_active,
                'detail' => $retained ? ($retained->type === 'equity' ? null : "{$retained->label()} is not an equity account.") : 'Choose it in Accounting → Settings.'],
        ];

        return ['checks' => $checks, 'revenue' => $revenue, 'expense' => $expense, 'result' => round($revenue - $expense, 2), 'retained_account' => $retained?->label(),
            'next_year' => $next?->name, 'can_close' => $year->status === 'open' && collect($checks)->every('ok')];
    }

    public function request(FiscalYear $year, User $by): FiscalYear
    {
        if ($year->status !== 'open') {
            throw ValidationException::withMessages(['year' => 'This year is not open.']);
        }
        $failed = collect($this->preview($year)['checks'])->reject(fn ($c) => $c['ok']);
        if ($failed->isNotEmpty()) {
            throw ValidationException::withMessages(['year' => $failed->map(fn ($c) => $c['label'].': '.$c['detail'])->values()->all()]);
        }

        return DB::transaction(function () use ($year, $by) {
            $year->update(['status' => 'closing', 'close_requested_by' => $by->id, 'decision_comment' => null]);
            if (! $this->workflow->start(YearEndCloseProcess::KEY, $year, $by)) {
                $this->approved($year);
            }

            return $year->refresh();
        });
    }

    public function approved(FiscalYear $year): void
    {
        DB::transaction(function () use ($year) {
            $by = User::find($year->close_requested_by);
            $end = $year->end_date->toDateString();
            if (! FiscalYear::whereDate('start_date', $year->end_date->copy()->addDay()->toDateString())->exists()) {
                $this->calendar->createYear($year->end_date->copy()->addDay()->toDateString());
            }
            $lines = $this->closingLines($year);
            $journal = null;
            if ($lines) {
                $retained = AccountingSetting::current()->retained_earnings_account_id;
                $byFund = collect($lines)->groupBy(fn ($l) => $l['fund_source_id'] ?? 0)->map(fn ($g) => round($g->sum('debit') - $g->sum('credit'), 2));
                foreach ($byFund as $fund => $net) {
                    if ($net != 0) {
                        $lines[] = ['account_id' => $retained, 'fund_source_id' => $fund ?: null, 'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0, 'description' => 'Result for '.$year->name];
                    }
                }
                $journal = $this->whileOpen($year, fn () => $this->posting->post($end, "Year-end closing {$year->name}", $lines, $by, 'closing', $year));
            }
            $year->periods()->where('status', 'open')->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by?->id]);
            $year->update(['status' => 'closed', 'closing_journal_id' => $journal?->id, 'closed_at' => now()]);
            $this->snapshot($year);
        });
        User::find($year->close_requested_by)?->notify(new Notice('Year closed', "{$year->name} is closed; its statements are kept as approved.", '/accounting/year-end', 'calendar-check'));
    }

    public function rejected(FiscalYear $year, ?string $comment, bool $withdrawn): void
    {
        $year->update(['status' => 'open', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function reopen(FiscalYear $year, User $by, string $reason): FiscalYear
    {
        if ($year->status !== 'closed') {
            throw ValidationException::withMessages(['year' => 'This year is not closed.']);
        }
        if (FiscalYear::whereDate('start_date', '>', $year->start_date)->where('status', '!=', 'open')->exists()) {
            throw ValidationException::withMessages(['year' => 'Reopen the later years first.']);
        }

        return DB::transaction(function () use ($year, $by, $reason) {
            if ($year->closingJournal && $year->closingJournal->status === 'posted') {
                $reversal = $this->whileOpen($year, fn () => $this->posting->reverse($year->closingJournal, $year->end_date->toDateString(), $by, "Year {$year->name} reopened: {$reason}"));
                $reversal->update(['entry_type' => 'closing']);
            }
            $year->statements()->delete();
            $year->update(['status' => 'open', 'closing_journal_id' => null, 'closed_at' => null, 'decision_comment' => "Reopened: {$reason}"]);
            $year->periods()->reorder('number', 'desc')->first()?->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

            return $year->refresh();
        });
    }

    /** Posts into the last day of the year even though its period is locked, then locks it again. */
    private function whileOpen(FiscalYear $year, callable $work)
    {
        $last = $year->periods()->reorder('number', 'desc')->first();
        $states = [$year->status, $last->status];
        $year->update(['status' => 'open']);
        $last->update(['status' => 'open']);
        $result = $work();
        $year->update(['status' => $states[0]]);
        $last->update(['status' => $states[1]]);

        return $result;
    }

    private function snapshot(FiscalYear $year): void
    {
        $reports = app(FinanceReports::class);
        $from = $year->start_date->toDateString();
        $to = $year->end_date->toDateString();
        $inputs = ['income-statement' => ['from' => $from, 'to' => $to], 'financial-position' => ['date' => $to], 'trial-balance' => ['from' => $from, 'to' => $to]];
        foreach ($inputs as $key => $input) {
            $r = $reports->build($key, $input);
            FiscalYearStatement::updateOrCreate(['fiscal_year_id' => $year->id, 'report' => $key], ['title' => $r['title'], 'subtitle' => $r['subtitle'], 'columns' => $r['columns'], 'rows' => $r['rows']]);
        }
    }
}
