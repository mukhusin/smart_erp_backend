<?php

namespace Modules\Hr\Support;

use App\Models\PayrollSheet;
use App\Models\UploadedPayroll;
use Illuminate\Support\Collection;

/**
 * Parallel run: an ERP payroll set beside the salary file of the same month (what HRMS paid), employee by employee and
 * amount by amount. Differences of less than one shilling are rounding.
 */
class PayrollFileCheck
{
    public const FIELDS = [
        'gross' => 'Gross pay', 'paye' => 'PAYE', 'nssf' => 'NSSF', 'nhif' => 'NHIF', 'heslb' => 'HESLB', 'fund' => 'Emergency fund',
        'loans' => 'Loans', 'saccos' => 'SACCOS', 'advance' => 'Salary advance', 'other' => 'Other deductions', 'net' => 'Net pay',
    ];

    private const TOLERANCE = 1.0;

    public function uploadFor(PayrollSheet $sheet): ?UploadedPayroll
    {
        return UploadedPayroll::where('year', (int) $sheet->year)->where('month', (int) $sheet->month)->latest('id')->first();
    }

    public function check(PayrollSheet $sheet, UploadedPayroll $upload): array
    {
        $erp = $sheet->details()->with('employee.department')->get()->keyBy('employee_id');
        $file = $upload->rows()->with('employee.department')->whereNotNull('employee_id')->get()->keyBy('employee_id');
        $rows = $erp->keys()->merge($file->keys())->unique()->map(function ($id) use ($erp, $file) {
            $d = $erp->get($id);
            $f = $file->get($id);
            $a = $d ? $this->fromDetail($d) : null;
            $b = $f ? $this->fromFile($f->data ?? []) : null;
            $employee = $d?->employee ?? $f?->employee;
            $diffs = [];
            if ($a && $b) {
                foreach (self::FIELDS as $k => $label) {
                    if (abs($a[$k] - $b[$k]) >= self::TOLERANCE) {
                        $diffs[] = ['key' => $k, 'label' => $label, 'erp' => $a[$k], 'file' => $b[$k], 'difference' => round($a[$k] - $b[$k], 2)];
                    }
                }
            }

            return [
                'employee_id' => (int) $id, 'employee_no' => $employee?->employee_no, 'name' => $d?->full_name ?: $employee?->full_name, 'department' => $employee?->department?->name,
                'status' => ! $a ? 'only_file' : (! $b ? 'only_erp' : ($diffs ? 'different' : 'match')),
                'erp_net' => $a['net'] ?? null, 'file_net' => $b['net'] ?? null, 'net_difference' => $a && $b ? round($a['net'] - $b['net'], 2) : null,
                'differences' => $diffs, 'reason' => $diffs ? collect($diffs)->where('key', '!=', 'net')->pluck('label')->implode(', ') : null,
                'erp' => $a, 'file' => $b,
            ];
        })->sortBy([fn ($x, $y) => array_search($x['status'], ['different', 'only_erp', 'only_file', 'match']) <=> array_search($y['status'], ['different', 'only_erp', 'only_file', 'match']), fn ($x, $y) => abs($y['net_difference'] ?? 0) <=> abs($x['net_difference'] ?? 0)])->values();

        $sum = fn (Collection $c, string $side, string $k) => round($c->sum(fn ($r) => $r[$side][$k] ?? 0), 2);
        $both = $rows->filter(fn ($r) => $r['erp'] && $r['file']);

        return [
            'sheet' => ['id' => $sheet->id, 'name' => $sheet->name, 'status' => $sheet->status], 'upload' => ['id' => $upload->id, 'file_name' => $upload->file_name],
            'counts' => ['match' => $rows->where('status', 'match')->count(), 'different' => $rows->where('status', 'different')->count(),
                'only_erp' => $rows->where('status', 'only_erp')->count(), 'only_file' => $rows->where('status', 'only_file')->count()],
            'totals' => collect(self::FIELDS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'erp' => $sum($rows, 'erp', $k), 'file' => $sum($rows, 'file', $k),
                'difference' => round($sum($rows, 'erp', $k) - $sum($rows, 'file', $k), 2), 'employees_different' => $both->filter(fn ($r) => abs($r['erp'][$k] - $r['file'][$k]) >= self::TOLERANCE)->count()])->values()->all(),
            'employees' => $rows->all(),
        ];
    }

    /** @return array<string, float> */
    private function fromDetail($d): array
    {
        $v = fn ($c) => round((float) $d->{$c}, 2);

        return ['gross' => $v('gross_salary'), 'paye' => $v('paye'), 'nssf' => $v('nssf'), 'nhif' => $v('nhif'), 'heslb' => $v('heslb'), 'fund' => $v('ef'),
            'loans' => $v('total_loan'), 'saccos' => $v('saccos_serving'), 'advance' => $v('advance'), 'other' => round($v('other_deduction') + $v('tughe') + $v('nic'), 2), 'net' => $v('net_salary')];
    }

    /** @return array<string, float> */
    private function fromFile(array $r): array
    {
        $n = fn (string ...$keys) => round(array_sum(array_map(fn ($k) => is_numeric($r[$k] ?? null) ? (float) $r[$k] : 0.0, $keys)), 2);
        $net = $n('NET_PAY') ?: $n('NET_TO_BANK', 'CASH');

        return ['gross' => $n('GROSS_SALARY'), 'paye' => $n('PAYE'), 'nssf' => $n('NSSF'), 'nhif' => $n('NHIF_2_PERCENT', 'NHIF_3_PERCENT'), 'heslb' => $n('HESLB', 'HESLB_LOAN'),
            'fund' => $n('E_FUND'), 'loans' => $n('NMB_LOAN', 'STAFF_LOAN', 'SACCOS_LOAN'), 'saccos' => $n('SACCOS_SAVINGS'), 'advance' => $n('SALARY_ADVANCE'),
            'other' => $n('INSURANCE', 'TUGHE', 'NIC'), 'net' => $net];
    }
}
