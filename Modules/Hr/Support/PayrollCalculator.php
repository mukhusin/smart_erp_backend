<?php

namespace Modules\Hr\Support;

use App\Models\AllowanceCategory;
use App\Models\DailyAllowance;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeFund;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\Heslb;
use App\Models\Nhif;
use App\Models\Nic;
use App\Models\Nssf;
use App\Models\Overtime;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheet;
use App\Models\Relief;
use App\Models\SaccosMember;
use App\Models\Tughe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Hr\Actions\ManageOvertime;

/**
 * One employee's pay for a payroll period (HRMS payroll sheet calculation).
 *
 * Basic: each contract of a payroll contract type that covers part of the period pays its basic for the days it covers
 * (joiners, leavers and renewals with a new salary are pro-rated); unpaid leave days are taken off at basic ÷ days in
 * the period. Gross = basic + approved allowances + approved overtime + relief. NSSF = rate × gross; NHIF, HESLB =
 * rate × basic; TUGHE = rate × contract basic; NIC and the employee fund are amounts. Taxable = gross − NSSF −
 * non-taxable allowances; PAYE by the bands, or a flat rate for secondary employment. Deductions also take loan
 * instalments, salary advances and SACCOS contributions. Employer: NSSF, NHIF, WCF and SDL (rate × gross).
 */
class PayrollCalculator
{
    public function __construct(private readonly PayrollDateSetting $settings) {}

    /** Contracts that pay in the period. @return Collection<int, EmployeeContract> */
    public function contracts(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return EmployeeContract::with('natureOfContract')->where('employee_id', $employee->id)->whereNotIn('status', ['cancelled', 'pending'])
            ->get()->filter(fn ($c) => $c->natureOfContract?->has_payroll && $c->start_date && $c->start_date->lte($to)
                && (! $c->end_date || $c->end_date->gte($from)) && (! $c->date_of_change || $c->date_of_change->gte($from)))
            ->sortBy('start_date')->values();
    }

    public function pays(Employee $employee, Carbon $from, Carbon $to): bool
    {
        return $this->contracts($employee, $from, $to)->isNotEmpty();
    }

    /** @return array{detail: array<string, mixed>, allowance_ids: list<int>, overtime: Collection, loans: list<array{loan: EmployeeLoan, instalment: float}>, advance_ids: list<int>} */
    public function calculate(Employee $employee, PayrollSheet $sheet, ?int $detailId = null): array
    {
        $s = $this->settings;
        $from = $sheet->payroll_from_date->copy()->startOfDay();
        $to = $sheet->payroll_to_date->copy()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $lines = [];
        $money = fn ($v) => round((float) $v, 2);

        $contracts = $this->contracts($employee, $from, $to);
        $current = $contracts->last();
        $basic = 0.0;
        $paidDays = 0;
        foreach ($contracts as $c) {
            $start = $c->start_date->max($from);
            $end = collect([$to, $c->end_date, $c->date_of_change])->filter()->min();
            $covered = max(0, (int) $start->diffInDays($end) + 1);
            $part = $covered >= $days ? (float) $c->basic_salary : $money($c->basic_salary * $covered / $days);
            $basic += $part;
            $paidDays += min($covered, $days);
            $lines[] = ['group' => 'earning', 'code' => 'BASIC', 'label' => 'Basic salary'.($covered < $days ? " ({$covered} of {$days} days)" : ''), 'amount' => $part];
        }

        $unpaidDays = 0.0;
        $unpaid = 0.0;
        if ($s->use_leave_deduction && $current) {
            $leaveDays = app(LeaveDays::class);
            EmpLeave::with('leaveType')->where('employee_id', $employee->id)->whereIn('status', EmpLeave::TAKEN)
                ->whereHas('leaveType', fn ($q) => $q->where('with_pay', false))
                ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get()
                ->each(function (EmpLeave $l) use ($leaveDays, $employee, $from, $to, &$unpaidDays) {
                    $unpaidDays += $leaveDays->count($employee, $l->leaveType, $l->start_date->max($from), $l->end_date->min($to));
                });
            $unpaid = $money(min($basic, $unpaidDays * (float) $current->basic_salary / $days));
            if ($unpaid > 0) {
                $lines[] = ['group' => 'earning', 'code' => 'UNPAID', 'label' => "Leave without pay ({$unpaidDays} days)", 'amount' => -$unpaid];
            }
        }
        $basic = $money($basic - $unpaid);

        $allowances = collect();
        $nonTaxable = 0.0;
        if ($s->use_allowance) {
            $allowances = DailyAllowance::where('employee_id', $employee->id)->where('status', 'approved')
                ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)
                ->where(fn ($q) => $q->whereNull('payroll_sheet_detail_id')->when($detailId, fn ($q) => $q->orWhere('payroll_sheet_detail_id', $detailId)))->get();
            $taxable = AllowanceCategory::whereIn('id', $allowances->pluck('allowance_category_id')->filter()->unique())->pluck('taxable', 'id');
            foreach ($allowances->groupBy('category') as $name => $rows) {
                $amount = $money($rows->sum('amount'));
                $lines[] = ['group' => 'earning', 'code' => 'ALLOWANCE', 'label' => $name.' ('.rtrim(rtrim(number_format($rows->sum('items'), 2, '.', ''), '0'), '.').')', 'amount' => $amount];
                $nonTaxable += $rows->filter(fn ($r) => $r->allowance_category_id && ! ($taxable[$r->allowance_category_id] ?? true))->sum('amount');
            }
        }
        $totalAllowance = $money($allowances->sum('amount'));

        $overtime = collect();
        if ($s->use_overtime) {
            $overtime = Overtime::where('employee_id', $employee->id)->where('status', 'approved')->where('is_paid', false)
                ->whereDate('date_of_task_done', '<=', $to)
                ->whereDoesntHave('sheets', fn ($q) => $q->when($detailId, fn ($q) => $q->where('payroll_sheet_detail_id', '!=', $detailId)))->get();
            if ($overtime->isNotEmpty()) {
                $lines[] = ['group' => 'earning', 'code' => 'OVERTIME', 'label' => 'Overtime ('.ManageOvertime::duration((int) $overtime->sum('overtime_minutes')).')', 'amount' => $money($overtime->sum('amount'))];
            }
        }
        $totalOvertime = $money($overtime->sum('amount'));

        $relief = $s->use_relief ? $money(Relief::where('employee_id', $employee->id)->where('is_active', true)->sum('amount')) : 0.0;
        if ($relief) {
            $lines[] = ['group' => 'earning', 'code' => 'RELIEF', 'label' => 'Relief', 'amount' => $relief];
        }
        $gross = $money($basic + $totalAllowance + $totalOvertime + $relief);

        $use = (bool) $s->use_deduction;
        $nssfM = $use ? Nssf::where('employee_id', $employee->id)->where('is_active', true)->first() : null;
        $nhifM = $use ? Nhif::where('employee_id', $employee->id)->where('is_active', true)->first() : null;
        $heslbM = $use ? Heslb::where('employee_id', $employee->id)->where('is_active', true)->first() : null;
        $tugheM = $use ? Tughe::where('employee_id', $employee->id)->where('is_active', true)->first() : null;
        $nicM = $use ? Nic::where('employee_id', $employee->id)->where('is_active', true)->first() : null;
        $fundM = $use ? EmployeeFund::where('employee_id', $employee->id)->where('is_active', true)->first() : null;
        $nssf = $nssfM ? $money($nssfM->emp_rate * $gross / 100) : 0.0;
        $nssfEr = $nssfM ? $money($nssfM->employer_rate * $gross / 100) : 0.0;
        $nhif = $nhifM ? $money($nhifM->emp_rate * $basic / 100) : 0.0;
        $nhifEr = $nhifM ? $money($nhifM->employer_rate * $basic / 100) : 0.0;
        $heslb = $heslbM ? $money($heslbM->emp_rate * $basic / 100) : 0.0;
        $tughe = $tugheM && $current ? $money($tugheM->emp_rate * $current->basic_salary / 100) : 0.0;
        $nic = $nicM ? $money($nicM->emp_rate) : 0.0;
        $fund = $fundM ? $money($fundM->amount) : 0.0;

        $taxableIncome = $money(max(0, $gross - $nssf - $nonTaxable));
        $secondary = $employee->emp_category === 'Secondary';
        $paye = $money($secondary ? $taxableIncome * (float) $s->income_tax / 100 : self::paye($taxableIncome, $s->bands()));

        $loans = [];
        if ($s->use_loan) {
            foreach (EmployeeLoan::with('loanProvider')->where('employee_id', $employee->id)->where('is_active', true)->where('status', 'enabled')
                ->where('remain', '>', 0)->whereDate('date_of_loan', '<=', $to)->get() as $loan) {
                $loans[] = ['loan' => $loan, 'instalment' => $money(min((float) $loan->instalment, (float) $loan->remain))];
            }
        }
        $totalLoan = $money(collect($loans)->sum('instalment'));

        $advances = $s->use_loan ? EmployeeMonthlyAdvance::where('employee_id', $employee->id)->where('is_active', true)
            ->where(fn ($q) => $q->where('status', 'pending')->whereNull('payroll_sheet_detail_id')->when($detailId, fn ($q) => $q->orWhere('payroll_sheet_detail_id', $detailId)))
            ->whereDate('date_advanced', '<=', $to)->get() : collect();
        $advance = $money($advances->sum('amount'));
        $saccos = $s->use_saccos ? $money(SaccosMember::where('employee_id', $employee->id)->where('is_active', true)->sum('amount')) : 0.0;

        foreach ([['PAYE', $secondary ? 'PAYE (secondary employment, '.$s->income_tax.'%)' : 'PAYE', $paye], ['NSSF', 'NSSF', $nssf], ['NHIF', 'NHIF', $nhif], ['HESLB', 'HESLB loan', $heslb],
            ['TUGHE', 'TUGHE', $tughe], ['NIC', 'NIC', $nic], ['FUND', 'Employee fund', $fund], ['SACCOS', 'SACCOS', $saccos], ['ADVANCE', 'Salary advance', $advance]] as [$code, $label, $amount]) {
            if ($amount > 0) {
                $lines[] = ['group' => 'deduction', 'code' => $code, 'label' => $label, 'amount' => $amount];
            }
        }
        foreach ($loans as $l) {
            $lines[] = ['group' => 'deduction', 'code' => 'LOAN', 'label' => 'Loan: '.($l['loan']->loanProvider?->name ?? 'loan'), 'amount' => $l['instalment']];
        }
        $totalDeduction = $money($paye + $nssf + $nhif + $heslb + $tughe + $nic + $fund + $saccos + $advance + $totalLoan);
        $wcf = $money($gross * (float) $s->wcf / 100);
        $sdl = $money($gross * (float) $s->sdl / 100);
        foreach ([['NSSF_ER', 'NSSF (employer)', $nssfEr], ['NHIF_ER', 'NHIF (employer)', $nhifEr], ['WCF', 'WCF', $wcf], ['SDL', 'SDL', $sdl]] as [$code, $label, $amount]) {
            if ($amount > 0) {
                $lines[] = ['group' => 'employer', 'code' => $code, 'label' => $label, 'amount' => $amount];
            }
        }
        $bank = $employee->bankAccount()->with('bank')->first();

        return [
            'detail' => [
                'employee_id' => $employee->id, 'contract_id' => $current?->id, 'full_name' => $employee->full_name, 'job_position' => $employee->jobPosition?->title,
                'basic_salary' => $basic, 'actual_basic_salary' => (float) ($current?->basic_salary ?? 0), 'leave_deduction' => $unpaid, 'unpaid_days' => $unpaidDays, 'days_paid' => $paidDays,
                'total_allowance' => $totalAllowance, 'total_overtime' => $totalOvertime, 'allowance_overtime' => $money($totalAllowance + $totalOvertime), 'relief' => $relief,
                'gross_salary' => $gross, 'nssf' => $nssf, 'nssf_employer' => $nssfEr, 'nhif' => $nhif, 'nhif_employer' => $nhifEr, 'heslb' => $heslb, 'tughe' => $tughe,
                'nic' => $nic, 'ef' => $fund, 'amount_taxable' => $taxableIncome, 'paye' => $paye, 'total_loan' => $totalLoan, 'advance' => $advance, 'saccos_serving' => $saccos,
                'attendance_deduction' => 0, 'total_deduction' => $totalDeduction, 'net_salary' => $money($gross - $totalDeduction), 'wcf' => $wcf, 'sdl' => $sdl,
                'emp_category' => $employee->emp_category, 'lines' => $lines,
                'bank_id' => $bank?->bank_id, 'bank_name' => $bank?->bank?->name, 'bank_branch' => $bank?->branch, 'bank_code' => $bank?->bank?->code, 'bank_account' => $bank?->account_number,
            ],
            'allowance_ids' => $allowances->pluck('id')->all(), 'overtime' => $overtime, 'loans' => $loans, 'advance_ids' => $advances->pluck('id')->all(),
        ];
    }

    /** PAYE on a monthly taxable income by progressive bands. @param list<array{up_to: float|int|null, rate: float|int}> $bands */
    public static function paye(float $taxable, array $bands): float
    {
        $tax = 0.0;
        $lower = 0.0;
        foreach ($bands as $band) {
            $upper = $band['up_to'] === null ? INF : (float) $band['up_to'];
            if ($taxable > $lower) {
                $tax += (min($taxable, $upper) - $lower) * (float) $band['rate'] / 100;
            }
            $lower = $upper;
            if ($taxable <= $upper) {
                break;
            }
        }

        return round($tax, 2);
    }
}
