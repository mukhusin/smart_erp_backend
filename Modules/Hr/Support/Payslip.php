<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\Nhif;
use App\Models\Nssf;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\UploadedPayrollRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A payslip's figures for screen and print. Months paid in HRMS come from the uploaded salary file (what was really
 * paid); other months from the payroll lines. Adds year-to-date totals and the employee's statutory numbers.
 */
class Payslip
{
    /** HRMS salary file column => [group, code, label] (as on the HRMS payslip). */
    private const UPLOADED = [
        'BASIC_SALARY' => ['earning', 'BASIC', 'Basic Salary'], 'OVERTIME_ALLOW' => ['earning', 'OVERTIME', 'Overtime'],
        'NIGHT_ALLOW' => ['earning', 'ALLOWANCE', 'Night Allowance'], 'WEEKLY_ALL' => ['earning', 'ALLOWANCE', 'Weekly Allowance'],
        'RESP_ALLOW' => ['earning', 'ALLOWANCE', 'Responsibility Allowance'], 'DIALYSIS_EXTRA_DUTY' => ['earning', 'ALLOWANCE', 'Dialysis Allowance'],
        'RELIEF' => ['earning', 'RELIEF', 'Relief'], 'OTHER_ALL' => ['earning', 'ALLOWANCE', 'Other Allowance'], 'EXTRA_DUTY' => ['earning', 'ALLOWANCE', 'Extra Duty'],
        'PAYE' => ['deduction', 'PAYE', 'PAYE'], 'NHIF_2_PERCENT' => ['deduction', 'NHIF', 'NHIF 2%'], 'NHIF_3_PERCENT' => ['deduction', 'NHIF', 'NHIF 3%'],
        'NIC' => ['deduction', 'NIC', 'NIC'], 'HESLB' => ['deduction', 'HESLB', 'HESLB'], 'TUGHE' => ['deduction', 'TUGHE', 'TUGHE'], 'NSSF' => ['deduction', 'NSSF', 'NSSF 10%'],
        'INSURANCE' => ['deduction', 'OTHER', 'Insurance'], 'E_FUND' => ['deduction', 'FUND', 'Emergency Fund'], 'NMB_LOAN' => ['deduction', 'LOAN', 'NMB Loan'],
        'STAFF_LOAN' => ['deduction', 'LOAN', 'Staff Loan'], 'SALARY_ADVANCE' => ['deduction', 'ADVANCE', 'Salary Advance'], 'HESLB_LOAN' => ['deduction', 'HESLB', 'HESLB Loan'],
        'SACCOS_SAVINGS' => ['deduction', 'SACCOS', 'SACCOS Saving'], 'SACCOS_LOAN' => ['deduction', 'LOAN', 'SACCOS Loan'],
    ];

    /** Columns of the salary file template, in order. */
    public const TEMPLATE = [
        'ST_NO', 'NAME', 'TIN', 'NSSFA_CNO', 'DESG', 'BASIC_SALARY', 'OVERTIME_ALLOW', 'NIGHT_ALLOW', 'WEEKLY_ALL', 'RESP_ALLOW', 'DIALYSIS_EXTRA_DUTY', 'OTHER_ALL',
        'EXTRA_DUTY', 'RELIEF', 'GROSS_SALARY', 'PAYE', 'NSSF', 'NHIF_2_PERCENT', 'NHIF_3_PERCENT', 'NIC', 'HESLB', 'E_FUND', 'INSURANCE', 'TUGHE', 'NMB_LOAN',
        'STAFF_LOAN', 'SALARY_ADVANCE', 'HESLB_LOAN', 'SACCOS_SAVINGS', 'SACCOS_LOAN', 'NET_PAY', 'CASH', 'NET_TO_BANK', 'NHIF_4_PERCENT', 'NSSF_20_PERCENT', 'EMAIL_ADDRESS',
    ];

    /** Payslip of a payroll line, as worked out by the payroll. */
    public function forDetail(PayrollSheetDetail $d): array
    {
        $d->loadMissing('employee.user', 'payrollSheet');
        $sheet = $d->payrollSheet;

        return $this->build($d->employee, (int) $sheet->year, (int) $sheet->month, $d->id, $this->detailFigures($d), $d->job_position, $d->full_name, $d, $sheet);
    }

    public function forRow(UploadedPayrollRow $r): array
    {
        $r->loadMissing('upload', 'employee.user');
        $data = $r->data ?? [];
        $detail = $r->employee_id ? PayrollSheetDetail::where('employee_id', $r->employee_id)
            ->whereHas('payrollSheet', fn ($q) => $q->where('year', (string) $r->upload->year)->where('month', (string) $r->upload->month))->first() : null;

        return $this->build($r->employee, (int) $r->upload->year, (int) $r->upload->month, $r->id, $this->rowFigures($data),
            ($data['DESG'] ?? null) ?: $r->employee?->jobPosition?->title, ($data['NAME'] ?? null) ?: $r->employee?->full_name, $detail, null, $data);
    }

    /** The screen form (same shape as before), from print data. */
    public function screen(array $p): array
    {
        $pick = fn ($rows) => array_map(fn ($l) => ['label' => $l['label'], 'amount' => $l['amount']], $rows);

        return [
            'sheet' => $p['sheet_name'], 'period' => $p['period'], 'status' => 'approved', 'source' => $p['source'],
            'employee' => ['name' => $p['employee']['name'], 'employee_no' => $p['employee']['employee_no'], 'position' => $p['employee']['position'],
                'department' => $p['employee']['department'], 'bank' => $p['bank'], 'account' => $p['account']],
            'earnings' => $pick($p['earnings']), 'deductions' => $pick($p['deductions']),
            'employer' => array_map(fn ($c) => ['label' => $c['label'], 'amount' => $c['amount']], $p['contributions']),
            'gross' => $p['gross']['amount'], 'taxable' => $p['taxable'], 'total_deductions' => $p['total_deductions']['amount'], 'net' => $p['net']['amount'],
        ];
    }

    private function build(?Employee $employee, int $year, int $month, int $sn, array $f, ?string $position, ?string $name, ?PayrollSheetDetail $d, ?PayrollSheet $sheet, ?array $row = null): array
    {
        $ytd = $employee ? $this->ytd($employee->id, $year, $month) : [];
        $withYtd = fn (string $group) => $f['lines']->where('group', $group)->map(fn ($l) => $l + ['ytd' => $ytd[$this->key($l)] ?? 0.0])->values()->all();
        $nssf = $employee ? Nssf::where('employee_id', $employee->id)->value('contribution_no') : null;
        $nhif = $employee ? Nhif::where('employee_id', $employee->id)->value('contribution_no') : null;
        $start = Carbon::create($year, $month, 1);
        $basic = $row ? (float) ($row['BASIC_SALARY'] ?? 0) : (float) $d?->basic_salary;

        return [
            'sn' => $sn, 'source' => $row !== null ? 'upload' : 'payroll', 'year' => $year, 'month_no' => $month,
            'month' => $start->format('F, Y'),
            'sheet_name' => $sheet?->name ?? 'Salary '.$start->format('F Y'),
            'period' => $sheet ? $sheet->payroll_from_date->format('d M Y').' – '.$sheet->payroll_to_date->format('d M Y') : $start->format('F Y'),
            'employee' => [
                'name' => $name, 'position' => $position, 'employee_no' => $employee?->employee_no, 'department' => $employee?->department?->name,
                'phone' => $employee?->phone_number, 'email' => $employee?->email ?: $employee?->user?->email,
                'tin' => $employee?->tin ?: ($row['TIN'] ?? null), 'nssf' => $employee?->nssf_no ?: $nssf ?: ($row['NSSFA_CNO'] ?? null), 'nhif' => $employee?->nhif_no ?: $nhif,
            ],
            'bank' => $d?->bank_name, 'account' => $d?->bank_account, 'taxable' => $row ? null : $d?->amount_taxable,
            'earnings' => $withYtd('earning'),
            'deductions' => $withYtd('deduction'),
            'gross' => ['amount' => $f['gross'], 'ytd' => $ytd['GROSS'] ?? 0.0],
            'total_deductions' => ['amount' => $f['deductions'], 'ytd' => $ytd['DEDUCTIONS'] ?? 0.0],
            'net' => ['amount' => $f['net'], 'ytd' => $ytd['NET'] ?? 0.0],
            'pay_by' => $f['pay_by'],
            'contributions' => collect($f['contributions'])->map(fn ($c) => $c + ['ytd' => $ytd['C:'.$c['label']] ?? 0.0])->all(),
            'attendance_note' => $d && ! $f['lines']->contains('code', 'ATTENDANCE') && (float) $d->attendance_deduction > 0
                ? 'Based on your attendance, you were supposed to be deducted Tsh '.number_format((float) $d->attendance_deduction, 2)
                    .', which would result in your basic salary being Tsh '.number_format($basic - (float) $d->attendance_deduction, 2).'.'
                : null,
        ];
    }

    private function rowFigures(array $row): array
    {
        $num = fn (string $k) => is_numeric($row[$k] ?? null) ? (float) $row[$k] : 0.0;
        $lines = collect(self::UPLOADED)->map(fn ($m, $col) => ['group' => $m[0], 'code' => $m[1], 'label' => $m[2], 'amount' => $num($col)])->filter(fn ($l) => $l['amount'] != 0)->values();
        $cash = $num('CASH');
        $nhifEr = $num('NHIF_4_PERCENT') ?: $num('NHIF_3_PERCENT');
        $deductions = round($lines->where('group', 'deduction')->sum('amount'), 2);

        return [
            'lines' => $lines, 'gross' => $num('GROSS_SALARY') ?: round($lines->where('group', 'earning')->sum('amount'), 2), 'deductions' => $deductions,
            'net' => $num('NET_PAY') ?: ($num('NET_TO_BANK') + $cash), 'pay_by' => $cash > 0 && $num('NET_TO_BANK') == 0 ? 'CASH' : 'BANK',
            'contributions' => array_values(array_filter([
                $nhifEr ? ['label' => "Employer's medical contribution NHIF", 'amount' => $nhifEr] : null,
                $num('NSSF_20_PERCENT') ? ['label' => 'Total contribution NSSF 20% of gross salary', 'amount' => $num('NSSF_20_PERCENT')] : null,
            ])),
        ];
    }

    private function detailFigures(PayrollSheetDetail $d): array
    {
        $lines = $this->lines($d);
        $employer = $lines->where('group', 'employer')->keyBy('code');

        return [
            'lines' => $lines, 'gross' => (float) $d->gross_salary, 'deductions' => (float) $d->total_deduction, 'net' => (float) $d->net_salary,
            'pay_by' => $d->bank_account ? 'BANK' : 'CASH',
            'contributions' => array_values(array_filter([
                $employer->has('NHIF_ER') ? ['label' => "Employer's medical contribution NHIF", 'amount' => $employer['NHIF_ER']['amount']] : null,
                $employer->has('NSSF_ER') ? ['label' => 'Total contribution NSSF (employee + employer)', 'amount' => round($employer['NSSF_ER']['amount'] + (float) $d->nssf, 2)] : null,
            ])),
        ];
    }

    /**
     * An employee's payslips, newest first: each month from its uploaded salary file when there is one (what was paid),
     * otherwise from approved payroll.
     *
     * @return list<array{source: string, id: int, year: int, month: int, sheet: string, gross: float, deductions: float, net: float}>
     */
    public function months(int $employeeId): array
    {
        $out = [];
        foreach (UploadedPayrollRow::with('upload')->where('employee_id', $employeeId)->orderBy('id')->get() as $r) {
            $f = $this->rowFigures($r->data ?? []);
            $out[$r->upload->year.'-'.str_pad((string) $r->upload->month, 2, '0', STR_PAD_LEFT)] = ['source' => 'upload', 'id' => $r->id, 'year' => (int) $r->upload->year,
                'month' => (int) $r->upload->month, 'sheet' => 'Salary '.Carbon::create($r->upload->year, $r->upload->month, 1)->format('F Y'),
                'gross' => $f['gross'], 'deductions' => $f['deductions'], 'net' => $f['net']];
        }
        foreach (PayrollSheetDetail::with('payrollSheet')->where('employee_id', $employeeId)->whereHas('payrollSheet', fn ($q) => $q->where('status', 'approved'))->get() as $d) {
            $key = $d->payrollSheet->year.'-'.str_pad((string) $d->payrollSheet->month, 2, '0', STR_PAD_LEFT);
            $out[$key] ??= ['source' => 'payroll', 'id' => $d->id, 'year' => (int) $d->payrollSheet->year, 'month' => (int) $d->payrollSheet->month,
                'sheet' => $d->payrollSheet->name, 'gross' => (float) $d->gross_salary, 'deductions' => (float) $d->total_deduction, 'net' => (float) $d->net_salary];
        }
        krsort($out);

        return array_values($out);
    }

    /** @return Collection<int, array{group: string, code: string, label: string, amount: float}> */
    public function lines(PayrollSheetDetail $d): Collection
    {
        if (! empty($d->lines)) {
            return collect($d->lines)->map(fn ($l) => ['group' => $l['group'], 'code' => $l['code'] ?? 'OTHER', 'label' => $l['label'], 'amount' => (float) $l['amount']]);
        }
        $rows = [
            ['earning', 'BASIC', 'Basic salary', $d->basic_salary], ['earning', 'ALLOWANCE', 'Allowances', $d->total_allowance], ['earning', 'OVERTIME', 'Overtime', $d->total_overtime],
            ['deduction', 'PAYE', 'PAYE', $d->paye], ['deduction', 'NHIF', 'NHIF', $d->nhif], ['deduction', 'NSSF', 'NSSF', $d->nssf], ['deduction', 'HESLB', 'HESLB loan', $d->heslb],
            ['deduction', 'TUGHE', 'TUGHE', $d->tughe], ['deduction', 'NIC', 'NIC', $d->nic], ['deduction', 'FUND', 'Emergency fund', $d->ef], ['deduction', 'SACCOS', 'SACCOS', $d->saccos_serving],
            ['deduction', 'ADVANCE', 'Salary advance', $d->advance], ['deduction', 'LOAN', 'Loans', $d->total_loan], ['deduction', 'OTHER', 'Other deductions', $d->other_deduction],
            ['employer', 'NSSF_ER', 'NSSF (employer)', $d->nssf_employer], ['employer', 'NHIF_ER', 'NHIF (employer)', $d->nhif_employer], ['employer', 'WCF', 'WCF', $d->wcf], ['employer', 'SDL', 'SDL', $d->sdl],
        ];

        return collect($rows)->filter(fn ($r) => (float) $r[3] != 0)->map(fn ($r) => ['group' => $r[0], 'code' => $r[1], 'label' => $r[2], 'amount' => (float) $r[3]])->values();
    }

    private function key(array $line): string
    {
        return in_array($line['code'], ['ALLOWANCE', 'OTHER', 'LOAN'], true) ? $line['code'].':'.strtolower(trim(preg_replace('/\s*\(.*\)$/', '', $line['label']))) : $line['code'];
    }

    /** Totals of the year up to the month: each month from its uploaded salary file, or else from approved payroll. @return array<string, float> */
    private function ytd(int $employeeId, int $year, int $month): array
    {
        $rows = UploadedPayrollRow::with('upload')->where('employee_id', $employeeId)->whereHas('upload', fn ($q) => $q->where('year', $year)->where('month', '<=', $month))
            ->orderBy('id')->get()->keyBy(fn ($r) => (int) $r->upload->month);
        $details = PayrollSheetDetail::with('payrollSheet')->where('employee_id', $employeeId)
            ->whereHas('payrollSheet', fn ($q) => $q->where('year', (string) $year)->where('status', 'approved'))->get()
            ->filter(fn ($d) => (int) $d->payrollSheet->month <= $month)->keyBy(fn ($d) => (int) $d->payrollSheet->month);
        $out = [];
        $add = function (string $k, float $v) use (&$out) {
            $out[$k] = ($out[$k] ?? 0) + $v;
        };
        for ($m = 1; $m <= $month; $m++) {
            $f = $rows->has($m) ? $this->rowFigures($rows[$m]->data ?? []) : ($details->has($m) ? $this->detailFigures($details[$m]) : null);
            if (! $f) {
                continue;
            }
            foreach ($f['lines'] as $l) {
                $add($this->key($l), $l['amount']);
            }
            foreach ($f['contributions'] as $c) {
                $add('C:'.$c['label'], $c['amount']);
            }
            $add('GROSS', $f['gross']);
            $add('DEDUCTIONS', $f['deductions']);
            $add('NET', $f['net']);
        }

        return $out;
    }
}
