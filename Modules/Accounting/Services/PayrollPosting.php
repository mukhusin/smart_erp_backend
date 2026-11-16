<?php

namespace Modules\Accounting\Services;

use App\Models\PayrollSheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\PayrollPostingSetting;

class PayrollPosting
{
    public function __construct(private readonly PostingService $posting) {}

    public function date(PayrollSheet $sheet): string
    {
        return ($sheet->payroll_to_date ?? Carbon::create((int) $sheet->year, (int) $sheet->month, 1)->endOfMonth())->toDateString();
    }

    /** @return list<array<string, mixed>> */
    public function lines(PayrollSheet $sheet): array
    {
        $s = PayrollPostingSetting::current();
        $missing = collect(PayrollPostingSetting::ACCOUNTS)->filter(fn ($l, $k) => ! $s->account($k) && ! in_array($k, ['employer', 'heslb', 'wcf', 'sdl', 'loans', 'other'], true));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['settings' => 'Choose these accounts in payroll posting settings: '.$missing->join(', ').'.']);
        }
        $details = $sheet->details()->with('employee')->get();
        $items = $this->budgetLines($sheet, [$s->account('salary'), $s->account('employer') ?? $s->account('salary')]);
        $lines = [];
        foreach ($details->groupBy(fn ($d) => $d->employee?->department_id) as $dept => $g) {
            $dept = $dept ?: null;
            $gross = round($g->sum('gross_salary'), 2);
            $employer = round($g->sum(fn ($d) => $d->nssf_employer + $d->nhif_employer + $d->wcf + $d->sdl), 2);
            $salaryAcc = $s->account('salary');
            $employerAcc = $s->account('employer') ?? $salaryAcc;
            $lines[] = ['account_id' => $salaryAcc, 'debit' => $gross, 'description' => 'Gross salaries', 'department_id' => $dept, 'budget_item_id' => $items[$dept.'|'.$salaryAcc] ?? null];
            if ($employer > 0) {
                $lines[] = ['account_id' => $employerAcc, 'debit' => $employer, 'description' => 'Employer contributions', 'department_id' => $dept, 'budget_item_id' => $items[$dept.'|'.$employerAcc] ?? null];
            }
        }
        $sum = fn ($c) => round($details->sum($c), 2);
        $credits = [
            'paye' => $sum('paye'), 'nssf' => round($sum('nssf') + $sum('nssf_employer'), 2), 'nhif' => round($sum('nhif') + $sum('nhif_employer'), 2),
            'heslb' => $sum('heslb'), 'wcf' => $sum('wcf'), 'sdl' => $sum('sdl'), 'loans' => $sum('total_loan'), 'net_pay' => $sum('net_salary'),
        ];
        $debits = round(array_sum(array_column($lines, 'debit')), 2);
        $credits['other'] = round($debits - array_sum($credits), 2);
        foreach ($credits as $key => $amount) {
            if (round($amount, 2) == 0) {
                continue;
            }
            $account = $s->account($key) ?? $s->account('other') ?? $s->account('net_pay');
            $lines[] = ['account_id' => $account, $amount > 0 ? 'credit' : 'debit' => abs($amount), 'description' => PayrollPostingSetting::ACCOUNTS[$key]];
        }

        return $lines;
    }

    public function post(PayrollSheet $sheet, ?User $by): JournalEntry
    {
        if ($sheet->status !== 'approved') {
            throw ValidationException::withMessages(['payroll' => 'Only an approved payroll is posted.']);
        }
        if ($sheet->journal_entry_id) {
            throw ValidationException::withMessages(['payroll' => 'This payroll is already posted.']);
        }
        $by ??= User::find($sheet->created_by);

        return DB::transaction(function () use ($sheet, $by) {
            $journal = $this->posting->post($this->date($sheet), "Payroll {$sheet->name}", $this->lines($sheet), $by, 'payroll', $sheet);
            $sheet->update(['journal_entry_id' => $journal->id, 'posting_error' => null]);

            return $journal;
        });
    }

    public function pay(PayrollSheet $sheet, int $bankId, string $date, User $by): JournalEntry
    {
        if (! $sheet->journal_entry_id || $sheet->payment_journal_id) {
            throw ValidationException::withMessages(['payroll' => $sheet->payment_journal_id ? 'Net salaries are already recorded as paid.' : 'Post the payroll to the ledger first.']);
        }
        $bank = CashBankAccount::where('is_active', true)->find($bankId);
        if (! $bank || ! $bank->account_id) {
            throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose an active bank account linked to a ledger account.']);
        }
        $net = round((float) $sheet->details()->sum('net_salary'), 2);

        return DB::transaction(function () use ($sheet, $bank, $date, $by, $net) {
            $journal = $this->posting->post($date, "Net salaries {$sheet->name} paid from {$bank->name}", [
                ['account_id' => PayrollPostingSetting::current()->account('net_pay'), 'debit' => $net, 'description' => 'Net salaries'],
                ['account_id' => $bank->account_id, 'credit' => $net, 'description' => "Payroll {$sheet->name}"],
            ], $by, 'payroll', $sheet);
            $sheet->update(['payment_journal_id' => $journal->id]);

            return $journal;
        });
    }

    /** @return array<string, int> "department|account" => budget item id in the approved budget covering the payroll date */
    private function budgetLines(PayrollSheet $sheet, array $accounts): array
    {
        if (! class_exists(\Modules\Budgeting\Models\BudgetItem::class)) {
            return [];
        }
        $date = $this->date($sheet);

        return \Modules\Budgeting\Models\BudgetItem::whereIn('account_id', array_filter($accounts))
            ->whereHas('budget', fn ($q) => $q->where('status', 'approved')->whereHas('fiscalYear', fn ($y) => $y->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)))
            ->orderBy('id')->get()->groupBy(fn ($i) => $i->department_id.'|'.$i->account_id)->map(fn ($g) => $g->first()->id)->all();
    }
}
