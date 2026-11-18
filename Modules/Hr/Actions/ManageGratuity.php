<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\GratuityAccrual;
use App\Models\GratuitySetting;
use App\Models\GratuitySettlement;
use App\Models\PayrollSheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageGratuity
{
    /** Accrue gratuity for the eligible staff on an approved payroll. */
    public function accrue(PayrollSheet $sheet): int
    {
        $s = GratuitySetting::current();
        if (! $s->enabled || $s->rate_percent <= 0) {
            return 0;
        }
        $types = $s->eligibleTypes();
        $details = $sheet->details()->get();
        $contracts = EmployeeContract::whereIn('id', $details->pluck('contract_id')->filter())->get()->keyBy('id');
        $n = 0;
        foreach ($details as $d) {
            $contract = $d->contract_id ? $contracts->get($d->contract_id) : null;
            if (! $contract || ! in_array($contract->nature_of_contract_id, $types)) {
                continue;
            }
            $base = round((float) ($s->base === 'gross' ? $d->gross_salary : ($d->actual_basic_salary ?: $d->basic_salary)), 2);
            $amount = round($base * $s->rate_percent / 100, 2);
            if ($amount <= 0) {
                continue;
            }
            GratuityAccrual::updateOrCreate(['payroll_sheet_id' => $sheet->id, 'employee_id' => $d->employee_id], [
                'employee_contract_id' => $contract->id, 'year' => (int) $sheet->year, 'month' => (int) $sheet->month, 'base_amount' => $base, 'rate_percent' => $s->rate_percent, 'amount' => $amount,
            ]);
            $n++;
        }

        return $n;
    }

    public function balance(Employee $employee): float
    {
        return round((float) GratuityAccrual::where('employee_id', $employee->id)->whereNull('gratuity_settlement_id')->sum('amount'), 2);
    }

    /** Pay out or forfeit everything accrued and not yet settled. */
    public function settle(Employee $employee, array $data, User $by): GratuitySettlement
    {
        $open = GratuityAccrual::where('employee_id', $employee->id)->whereNull('gratuity_settlement_id')->lockForUpdate()->get();
        $amount = round((float) $open->sum('amount'), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['employee' => 'Nothing is accrued for this employee.']);
        }
        $s = GratuitySetting::current();
        $ledger = $s->liability_account_id && class_exists(\Modules\Accounting\Services\PostingService::class);
        $bank = null;
        if ($data['kind'] === 'paid' && $ledger) {
            $bank = \Modules\Accounting\Models\CashBankAccount::where('is_active', true)->find($data['cash_bank_account_id'] ?? 0);
            if (! $bank || ! $bank->account_id) {
                throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose the bank or cash account it is paid from.']);
            }
        }
        if ($data['kind'] === 'forfeited' && $ledger && ! $s->expense_account_id) {
            throw ValidationException::withMessages(['kind' => 'Choose the gratuity expense account in the gratuity settings first.']);
        }

        return DB::transaction(function () use ($employee, $data, $by, $open, $amount, $s, $ledger, $bank) {
            $settlement = GratuitySettlement::create(['employee_id' => $employee->id, 'kind' => $data['kind'], 'date' => $data['date'], 'amount' => $amount,
                'cash_bank_account_id' => $bank?->id, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $by->id]);
            GratuityAccrual::whereIn('id', $open->pluck('id'))->update(['gratuity_settlement_id' => $settlement->id]);
            if ($ledger) {
                $other = $data['kind'] === 'paid' ? $bank->account_id : $s->expense_account_id;
                $journal = app(\Modules\Accounting\Services\PostingService::class)->post($data['date'], 'Gratuity '.($data['kind'] === 'paid' ? 'paid to ' : 'forfeited by ').$employee->full_name, [
                    ['account_id' => $s->liability_account_id, 'debit' => $amount, 'description' => "Gratuity {$employee->employee_no}"],
                    ['account_id' => $other, 'credit' => $amount, 'description' => $data['kind'] === 'paid' ? 'Gratuity paid' : 'Gratuity forfeited', 'department_id' => $employee->department_id],
                ], $by, 'payroll', $settlement);
                $settlement->update(['journal_entry_id' => $journal->id]);
            }

            return $settlement->refresh();
        });
    }
}
