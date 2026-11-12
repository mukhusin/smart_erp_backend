<?php

namespace Modules\Hr\Actions;

use App\Models\DailyAllowance;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\LoanHistory;
use App\Models\Overtime;
use App\Models\OvertimeSheet;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Support\PayrollCalculator;
use Modules\Hr\Workflow\PayrollSheetProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Payroll sheets (HRMS payroll): one per month, for the payroll period in the settings. A draft is worked out (and
 * again after changes); submitting sends it through the "Payroll sheet" approval workflow (HRMS: payroll officer,
 * director, finance). On approval it is locked: loan balances go down, salary advances are deducted, overtime is
 * marked paid and employees get their payslip.
 */
class ManagePayroll
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function create(int $year, int $month, User $by): PayrollSheet
    {
        if (PayrollSheet::where('year', (string) $year)->where('month', (string) $month)->exists()) {
            throw ValidationException::withMessages(['month' => 'There is already a payroll for '.Carbon::create($year, $month, 1)->format('F Y').'.']);
        }
        [$from, $to] = PayrollDateSetting::current()->period($year, $month);
        $sheet = PayrollSheet::create([
            'name' => 'Payroll '.Carbon::create($year, $month, 1)->format('F Y'), 'year' => (string) $year, 'month' => (string) $month,
            'payroll_from_date' => $from, 'payroll_to_date' => $to, 'number_of_days' => (int) $from->diffInDays($to) + 1,
            'status' => 'draft', 'created_by' => $by->id, 'hr_officer' => $by->employee?->id,
        ]);

        return $this->generate($sheet);
    }

    /** Works the sheet out again for everyone paid in the period. */
    public function generate(PayrollSheet $sheet): PayrollSheet
    {
        if (! $sheet->editable()) {
            throw ValidationException::withMessages(['payroll' => 'This payroll is '.strtolower(PayrollSheet::STATUS_LABELS[$sheet->status]).' and cannot change.']);
        }
        $calculator = new PayrollCalculator(PayrollDateSetting::current());
        $from = $sheet->payroll_from_date;
        $to = $sheet->payroll_to_date;

        DB::transaction(function () use ($sheet, $calculator, $from, $to) {
            $paid = [];
            foreach (Employee::with('jobPosition')->get() as $employee) {
                if (! $calculator->pays($employee, $from, $to)) {
                    continue;
                }
                $detail = PayrollSheetDetail::firstOrNew(['payroll_sheet_id' => $sheet->id, 'employee_id' => $employee->id]);
                $result = $calculator->calculate($employee, $sheet, $detail->id);
                $detail->fill($result['detail'] + ['is_active' => true])->save();
                $this->link($detail, $result);
                $paid[] = $detail->id;
            }
            $sheet->details()->whereNotIn('id', $paid)->get()->each(fn ($d) => $this->unlink($d) || $d->delete());
            $this->totals($sheet);
            $sheet->update(['generated_at' => now(), 'decision_comment' => $sheet->status === 'rejected' ? $sheet->decision_comment : null]);
        });

        return $sheet->refresh();
    }

    private function link(PayrollSheetDetail $detail, array $result): void
    {
        DailyAllowance::where('payroll_sheet_detail_id', $detail->id)->whereNotIn('id', $result['allowance_ids'])->update(['payroll_sheet_detail_id' => null]);
        DailyAllowance::whereIn('id', $result['allowance_ids'])->update(['payroll_sheet_detail_id' => $detail->id]);
        OvertimeSheet::where('payroll_sheet_detail_id', $detail->id)->delete();
        foreach ($result['overtime'] as $o) {
            OvertimeSheet::create(['employee_id' => $detail->employee_id, 'payroll_sheet_detail_id' => $detail->id, 'overtime_id' => $o->id, 'date_of_task_done' => $o->date_of_task_done->toDateString(), 'amount' => $o->amount]);
        }
        LoanHistory::where('payroll_sheet_detail_id', $detail->id)->where('status', 'pending')->delete();
        foreach ($result['loans'] as ['loan' => $loan, 'instalment' => $instalment]) {
            LoanHistory::create([
                'employee_id' => $detail->employee_id, 'payroll_sheet_id' => $detail->payroll_sheet_id, 'payroll_sheet_detail_id' => $detail->id, 'employee_loan_id' => $loan->id,
                'loan_provider' => $loan->loanProvider?->name ?? 'Loan', 'repayment_date' => $detail->payrollSheet->payroll_to_date->toDateString(),
                'repaid_amount' => (float) $loan->returned + $instalment, 'instalment' => $instalment, 'principle_amount' => (float) $loan->amount,
                'outstanding_loan' => max(0, (float) $loan->remain - $instalment), 'status' => 'pending',
            ]);
        }
        EmployeeMonthlyAdvance::where('payroll_sheet_detail_id', $detail->id)->whereNotIn('id', $result['advance_ids'])->update(['payroll_sheet_detail_id' => null]);
        EmployeeMonthlyAdvance::whereIn('id', $result['advance_ids'])->update(['payroll_sheet_detail_id' => $detail->id]);
    }

    private function unlink(PayrollSheetDetail $detail): bool
    {
        DailyAllowance::where('payroll_sheet_detail_id', $detail->id)->update(['payroll_sheet_detail_id' => null]);
        OvertimeSheet::where('payroll_sheet_detail_id', $detail->id)->delete();
        LoanHistory::where('payroll_sheet_detail_id', $detail->id)->where('status', 'pending')->delete();
        EmployeeMonthlyAdvance::where('payroll_sheet_detail_id', $detail->id)->update(['payroll_sheet_detail_id' => null]);

        return false;
    }

    private function totals(PayrollSheet $sheet): void
    {
        $d = $sheet->details();
        $employer = (float) $d->sum('nssf_employer') + (float) $d->sum('nhif_employer') + (float) $d->sum('wcf') + (float) $d->sum('sdl');
        $sheet->update([
            'employees_count' => $d->count(), 'gross_total' => round((float) $d->sum('gross_salary'), 2), 'deduction_total' => round((float) $d->sum('total_deduction'), 2),
            'net_total' => round((float) $d->sum('net_salary'), 2), 'employer_total' => round($employer, 2), 'payroll_cost' => round((float) $d->sum('gross_salary') + $employer, 2),
        ]);
    }

    public function submit(PayrollSheet $sheet, User $by): PayrollSheet
    {
        if (! $sheet->editable()) {
            throw ValidationException::withMessages(['payroll' => 'This payroll has already been submitted.']);
        }
        if (! $sheet->details()->exists()) {
            throw ValidationException::withMessages(['payroll' => 'Nobody is paid on this payroll.']);
        }
        $sheet->update(['status' => 'pending', 'submitted_at' => now(), 'decision_comment' => null]);
        if (! $this->workflow->start(PayrollSheetProcess::KEY, $sheet, $by)) {
            $this->approve($sheet);
        }

        return $sheet->refresh();
    }

    public function approve(PayrollSheet $sheet): void
    {
        DB::transaction(function () use ($sheet) {
            $sheet->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => auth()->user()?->employee?->id, 'director_approved' => true]);
            LoanHistory::where('payroll_sheet_id', $sheet->id)->where('status', 'pending')->get()->each(function (LoanHistory $h) {
                $loan = EmployeeLoan::find($h->employee_loan_id);
                if ($loan) {
                    $remain = max(0, (float) $loan->remain - (float) $h->instalment);
                    $loan->update(['returned' => (float) $loan->returned + (float) $h->instalment, 'remain' => $remain] + ($remain <= 0 ? ['status' => 'disabled', 'is_active' => false, 'end_date' => now()->toDateString()] : []));
                }
                $h->update(['status' => 'applied']);
            });
            $detailIds = $sheet->details()->pluck('id');
            EmployeeMonthlyAdvance::whereIn('payroll_sheet_detail_id', $detailIds)->update(['status' => 'deducted']);
            Overtime::whereIn('id', OvertimeSheet::whereIn('payroll_sheet_detail_id', $detailIds)->pluck('overtime_id'))->update(['is_paid' => true]);
        });
        $label = Carbon::create((int) $sheet->year, (int) $sheet->month, 1)->format('F Y');
        $sheet->details()->with('employee.user')->get()->each(fn ($d) => $d->employee?->user?->notify(
            new Notice('Payslip ready', "Your payslip for {$label} is ready. Net pay: ".number_format($d->net_salary, 2).'.', '/self-service/payslips', 'receipt')));
    }

    public function reject(PayrollSheet $sheet, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $sheet->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function withdraw(PayrollSheet $sheet, User $by): PayrollSheet
    {
        if ($sheet->status !== 'pending') {
            throw ValidationException::withMessages(['payroll' => 'Only a payroll waiting for approval can be withdrawn.']);
        }
        if ($instance = $sheet->workflow()->where('status', 'pending')->first()) {
            $this->workflow->cancel($instance, $by);
        }
        $sheet->update(['status' => 'draft']);

        return $sheet->refresh();
    }

    public function delete(PayrollSheet $sheet): void
    {
        if (! $sheet->editable()) {
            throw ValidationException::withMessages(['payroll' => 'Only a draft or returned payroll can be deleted.']);
        }
        DB::transaction(function () use ($sheet) {
            $sheet->details()->get()->each(fn ($d) => $this->unlink($d) || $d->delete());
            $sheet->delete();
        });
    }
}
