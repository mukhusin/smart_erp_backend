<?php

namespace App\Services;

use App\Models\AllowanceSheet;
use App\Models\AttendanceTransa;
use App\Models\DailyAllowance;
use App\Models\EmpAllowance;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeFund;
use App\Models\EmployeeLoan;
use App\Models\Heslb;
use App\Models\LoanHistory;
use App\Models\Nhif;
use App\Models\Nic;
use App\Models\Nssf;
use App\Models\Overtime;
use App\Models\OvertimeSheet;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\Relief;
use App\Models\SaccosContribution;
use App\Models\SaccosMember;
use App\Models\Tughe;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollComputationService
{
    private PayrollDateSetting $setting;
    private int $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
        $this->setting   = PayrollDateSetting::where('company_id', $companyId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    // ── Tanzania PAYE (TRA) brackets ─────────────────────────────────────────

    public function payeAmount(float $taxable): float
    {
        if ($taxable <= 270000)      return 0;
        if ($taxable <= 520000)      return 0.08 * ($taxable - 270000);
        if ($taxable <= 760000)      return 20000 + 0.20 * ($taxable - 520000);
        if ($taxable <= 1000000)     return 68000 + 0.25 * ($taxable - 760000);
        return 128000 + 0.30 * ($taxable - 1000000);
    }

    // ── Per-employee helpers ─────────────────────────────────────────────────

    public function basicSalary(int $empId): float
    {
        $contract = EmployeeContract::where('employee_id', $empId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return $contract ? (float) $contract->basic_salary : 0.0;
    }

    public function totalAllowance(int $empId): float
    {
        return (float) EmpAllowance::where('employee_id', $empId)
            ->where('is_active', true)
            ->where('is_hr_approved', true)
            ->sum('amount');
    }

    public function totalOvertime(int $empId): float
    {
        return (float) Overtime::where('employee_id', $empId)
            ->where('company_id', $this->companyId)
            ->where('is_hr_approved', true)
            ->where('is_paid', false)
            ->sum('amount');
    }

    public function grossSalary(int $empId): float
    {
        $basic    = $this->basicSalary($empId);
        $relief   = Relief::where('employee_id', $empId)->where('is_active', true)->first();
        $allowance = $this->setting->use_allowance ? $this->totalAllowance($empId) : 0;
        $overtime  = $this->setting->use_overtime  ? $this->totalOvertime($empId)  : 0;

        return $basic + $allowance + $overtime + ($relief ? $relief->amount : 0);
    }

    public function netSalary(int $empId): float
    {
        return $this->grossSalary($empId) - $this->totalDeductions($empId);
    }

    public function totalDeductions(int $empId): float
    {
        $gross   = $this->grossSalary($empId);
        $basic   = $this->basicSalary($empId);
        $nssf    = Nssf::where('employee_id', $empId)->where('is_active', true)->first();
        $nssfAmt = $nssf ? ($nssf->emp_rate / 100) * $gross : 0;
        $taxable = $gross - $nssfAmt;
        $paye    = $this->payeAmount($taxable);

        $nhif    = Nhif::where('employee_id', $empId)->where('is_active', true)->first();
        $nhifAmt = $nhif ? ($nhif->emp_rate / 100) * $basic : 0;

        $heslb    = Heslb::where('employee_id', $empId)->where('is_active', true)->first();
        $heslbAmt = $heslb ? ($heslb->emp_rate / 100) * $basic : 0;

        $tughe    = Tughe::where('employee_id', $empId)->where('is_active', true)->first();
        $tugheAmt = $tughe ? ($tughe->emp_rate / 100) * $basic : 0;

        $nic    = Nic::where('employee_id', $empId)->where('is_active', true)->first();
        $nicAmt = $nic ? $nic->emp_rate : 0;

        $fund    = EmployeeFund::where('employee_id', $empId)->where('is_active', true)->first();
        $fundAmt = $fund ? $fund->amount : 0;

        $loanAmt = $this->setting->use_loan
            ? EmployeeLoan::where('employee_id', $empId)->where('remain', '>', 0)
                ->where('status', 'enabled')->where('is_active', true)->sum('instalment')
            : 0;

        return $nssfAmt + $nhifAmt + $heslbAmt + $tugheAmt + $paye + $fundAmt + $nicAmt + $loanAmt;
    }

    // ── Payroll sheet generation ─────────────────────────────────────────────

    public function generate(PayrollSheet $sheet): bool
    {
        $lock = Cache::lock("payroll_generation_{$this->companyId}", 120);
        if (!$lock->get()) {
            return false;
        }

        try {
            $employees = EmployeeContract::with(['employee.bankAccounts', 'employee.jobPosition'])
                ->where('is_active', true)
                ->whereHas('employee', fn($q) => $q->where('company_id', $this->companyId))
                ->get();

            foreach ($employees as $contract) {
                $this->processEmployee($contract, $sheet);
            }

            $sheet->payroll_cost = $this->computePayrollCost($sheet->id);
            $sheet->status       = 'pending';
            $sheet->save();

            return true;
        } catch (\Exception $e) {
            Log::error("Payroll generation failed for company {$this->companyId}: " . $e->getMessage());
            return false;
        } finally {
            $lock->release();
        }
    }

    private function processEmployee(EmployeeContract $contract, PayrollSheet $sheet): void
    {
        try {
            $emp        = $contract->employee;
            $empId      = $emp->id;
            $fromDate   = $sheet->payroll_from_date;
            $toDate     = $sheet->payroll_to_date;
            $days       = $sheet->number_of_days;

            $basic    = (float) $contract->basic_salary;
            $bank     = EmployeeBankAccount::where('employee_id', $empId)->where('is_active', true)->first();

            $nhif    = Nhif::where('employee_id', $empId)->where('is_active', true)->first();
            $nssf    = Nssf::where('employee_id', $empId)->where('is_active', true)->first();
            $heslb   = Heslb::where('employee_id', $empId)->where('is_active', true)->first();
            $tughe   = Tughe::where('employee_id', $empId)->where('is_active', true)->first();
            $nic     = Nic::where('employee_id', $empId)->where('is_active', true)->first();
            $fund    = EmployeeFund::where('employee_id', $empId)->where('is_active', true)->first();
            $relief  = Relief::where('employee_id', $empId)->where('is_active', true)->first();

            $nhifEmp      = $nhif ? ($nhif->emp_rate      / 100) * $basic : 0;
            $nhifEmployer = $nhif ? ($nhif->employer_rate / 100) * $basic : 0;
            $heslbAmt     = $heslb ? ($heslb->emp_rate   / 100) * $basic : 0;
            $tugheAmt     = $tughe ? ($tughe->emp_rate   / 100) * $basic : 0;
            $nicAmt       = $nic ? $nic->emp_rate : 0;
            $efAmt        = $fund ? $fund->amount : 0;
            $reliefAmt    = $relief ? $relief->amount : 0;

            $totalAllowance = 0;
            $totalOvertime  = 0;

            if ($this->setting->use_allowance) {
                $totalAllowance = (float) EmpAllowance::where('employee_id', $empId)
                    ->where('is_active', true)
                    ->where('is_hr_approved', true)
                    ->sum('amount');
            }

            if ($this->setting->use_overtime) {
                $totalOvertime = (float) Overtime::where('employee_id', $empId)
                    ->where('company_id', $this->companyId)
                    ->where('is_hr_approved', true)
                    ->where('is_paid', false)
                    ->sum('amount');
            }

            $gross         = $basic + $totalAllowance + $totalOvertime + $reliefAmt;
            $nssfEmp       = $nssf ? ($nssf->emp_rate      / 100) * $gross : 0;
            $nssfEmployer  = $nssf ? ($nssf->employer_rate / 100) * $gross : 0;
            $taxable       = $gross - $nssfEmp;

            $empCategory   = $emp->emp_category ?? '';
            $paye = ($empCategory === 'Secondary')
                ? (($this->setting->income_tax ?? 30) / 100) * $gross
                : $this->payeAmount($taxable);

            $loanAmt = 0;
            if ($this->setting->use_loan) {
                $loanAmt = (float) EmployeeLoan::where('employee_id', $empId)
                    ->where('remain', '>', 0)
                    ->where('status', 'enabled')
                    ->where('is_active', true)
                    ->sum('instalment');
            }

            $wcf = $gross * (($this->setting->wcf ?? 0.5) / 100);
            $sdl = $gross * (($this->setting->sdl ?? 3.5) / 100);

            $totalDeduction = $nhifEmp + $nssfEmp + $heslbAmt + $tugheAmt + $paye + $efAmt + $nicAmt + $loanAmt;
            $netSalary      = $gross - $totalDeduction;

            $data = [
                'payroll_sheet_id'     => $sheet->id,
                'employee_id'          => $empId,
                'contract_id'          => $contract->id,
                'full_name'            => $emp->full_name,
                'job_position'         => optional($emp->jobPosition)->title,
                'basic_salary'         => $basic,
                'actual_basic_salary'  => $basic,
                'total_allowance'      => $totalAllowance,
                'total_overtime'       => $totalOvertime,
                'allowance_overtime'   => $totalAllowance + $totalOvertime,
                'relief'               => $reliefAmt,
                'gross_salary'         => $gross,
                'nhif'                 => $nhifEmp,
                'nhif_employer'        => $nhifEmployer,
                'nssf'                 => $nssfEmp,
                'nssf_employer'        => $nssfEmployer,
                'heslb'                => $heslbAmt,
                'tughe'                => $tugheAmt,
                'nic'                  => $nicAmt,
                'ef'                   => $efAmt,
                'amount_taxable'       => $taxable,
                'paye'                 => $paye,
                'total_loan'           => $loanAmt,
                'total_deduction'      => $totalDeduction,
                'net_salary'           => $netSalary,
                'wcf'                  => $wcf,
                'sdl'                  => $sdl,
                'bank_id'              => $bank?->bank_id,
                'bank_name'            => $bank?->name,
                'bank_branch'          => $bank?->branch,
                'bank_account'         => $bank?->account,
                'bank_code'            => $bank?->bank?->code ?? '',
            ];

            $existing = PayrollSheetDetail::where('payroll_sheet_id', $sheet->id)
                ->where('employee_id', $empId)
                ->first();

            if ($existing) {
                $existing->update($data);
                $detailId = $existing->id;
            } else {
                $detailId = PayrollSheetDetail::insertGetId(
                    array_merge($data, ['created_at' => now(), 'updated_at' => now()])
                );
            }

            // Link overtime records
            if ($this->setting->use_overtime) {
                $this->recordOvertimeSheet($empId, $detailId);
            }

            // Record allowances
            if ($this->setting->use_allowance) {
                $this->recordAllowanceSheet($empId, $detailId);
            }

            // Process loans
            if ($this->setting->use_loan) {
                $this->processLoanRepayment($empId, $detailId, $sheet, $toDate);
            }

            // SACCOS contribution
            $this->processSaccosContribution($empId, $detailId, $toDate);

        } catch (\Exception $e) {
            Log::error("Payroll: error for employee {$contract->employee_id}: " . $e->getMessage());
        }
    }

    private function recordOvertimeSheet(int $empId, int $detailId): void
    {
        $overtimes = Overtime::where('employee_id', $empId)
            ->where('company_id', $this->companyId)
            ->where('is_hr_approved', true)
            ->where('is_paid', false)
            ->get();

        foreach ($overtimes as $ot) {
            OvertimeSheet::updateOrCreate(
                ['employee_id' => $empId, 'overtime_id' => $ot->id],
                [
                    'payroll_sheet_detail_id' => $detailId,
                    'date_of_task_done'       => $ot->date_of_task_done,
                    'amount'                  => $ot->amount,
                ]
            );
            $ot->update(['is_paid' => true]);
        }
    }

    private function recordAllowanceSheet(int $empId, int $detailId): void
    {
        $allowances = EmpAllowance::where('employee_id', $empId)
            ->where('is_active', true)
            ->where('is_hr_approved', true)
            ->get();

        foreach ($allowances as $al) {
            AllowanceSheet::updateOrCreate(
                ['employee_id' => $empId, 'payroll_sheet_detail_id' => $detailId, 'emp_allowance_id' => $al->id],
                ['allowance_name' => $al->category, 'amount' => $al->amount]
            );
        }
    }

    private function processLoanRepayment(int $empId, int $detailId, PayrollSheet $sheet, string $toDate): void
    {
        $loans = EmployeeLoan::where('employee_id', $empId)
            ->where('remain', '>', 0)
            ->where('status', 'enabled')
            ->where('is_active', true)
            ->get();

        foreach ($loans as $loan) {
            $instalment    = min($loan->instalment, $loan->remain);
            $repaidAmount  = $loan->returned + $instalment;
            $outstanding   = $loan->amount - $repaidAmount;

            LoanHistory::updateOrCreate(
                ['employee_id' => $empId, 'payroll_sheet_detail_id' => $detailId, 'employee_loan_id' => $loan->id],
                [
                    'payroll_sheet_id'  => $sheet->id,
                    'loan_provider'     => $loan->loanProvider?->name ?? '',
                    'repayment_date'    => $toDate,
                    'repaid_amount'     => $repaidAmount,
                    'instalment'        => $instalment,
                    'principle_amount'  => $loan->amount,
                    'outstanding_loan'  => max(0, $outstanding),
                ]
            );

            $loan->returned = $repaidAmount;
            $loan->remain   = max(0, $outstanding);
            if ($loan->remain <= 0) {
                $loan->is_active = false;
                $loan->status    = 'disabled';
            }
            $loan->save();
        }
    }

    private function processSaccosContribution(int $empId, int $detailId, string $toDate): void
    {
        $member = SaccosMember::where('employee_id', $empId)->where('is_active', true)->first();
        if (!$member) return;

        $existing = SaccosContribution::where('saccos_member_id', $member->id)
            ->where('payroll_sheet_detail_id', $detailId)
            ->first();

        if ($existing) {
            $existing->amount = $member->amount;
            $existing->save();
        } else {
            SaccosContribution::create([
                'saccos_member_id'        => $member->id,
                'payroll_sheet_detail_id' => $detailId,
                'amount'                  => $member->amount,
                'date_contributed'        => $toDate,
            ]);
        }

        $member->total_contribution += $member->amount;
        $member->save();
    }

    private function computePayrollCost(int $sheetId): float
    {
        $t = PayrollSheetDetail::where('payroll_sheet_id', $sheetId)->selectRaw(
            'SUM(gross_salary) as gross, SUM(wcf) as wcf, SUM(sdl) as sdl,
             SUM(nhif_employer) as nhif_e, SUM(nssf_employer) as nssf_e'
        )->first();

        return ($t->gross ?? 0) + ($t->wcf ?? 0) + ($t->sdl ?? 0)
             + ($t->nhif_e ?? 0) + ($t->nssf_e ?? 0);
    }
}
