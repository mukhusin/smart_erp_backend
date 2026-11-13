<?php

namespace Modules\Hr\Support;

use App\Models\Heslb;
use App\Models\Nhif;
use App\Models\Nssf;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use Illuminate\Support\Collection;

/**
 * Payroll outputs: the bank payment list and the statutory returns (PAYE to TRA, NSSF, NHIF, HESLB, and WCF/SDL),
 * built from an approved (or draft, for checking) payroll sheet.
 */
class PayrollReports
{
    public const TYPES = [
        'bank' => 'Bank payment list', 'paye' => 'PAYE (TRA)', 'nssf' => 'NSSF contributions', 'nhif' => 'NHIF contributions',
        'heslb' => 'HESLB loan repayments', 'employer' => 'Employer costs (WCF, SDL)', 'full' => 'Full payroll',
    ];

    /** @return array{title: string, columns: list<string>, rows: list<list<string|float|null>>, totals: list<string|float|null>} */
    public function build(PayrollSheet $sheet, string $type, ?int $bankId = null): array
    {
        $details = $sheet->details()->with('employee')->orderBy('full_name')->get();
        $ids = $details->pluck('employee_id');
        $member = fn (string $model) => $model::whereIn('employee_id', $ids)->where('is_active', true)->pluck('contribution_no', 'employee_id');

        return match ($type) {
            'bank' => $this->table(['Employee no', 'Name', 'Bank', 'Bank code', 'Branch', 'Account number', 'Net pay'],
                $details->when($bankId, fn ($c) => $c->where('bank_id', $bankId))->map(fn ($d) => [$d->employee?->employee_no, $d->full_name, $d->bank_name, $d->bank_code, $d->bank_branch, $d->bank_account, $d->net_salary]), [6]),
            'paye' => $this->table(['Employee no', 'Name', 'TIN', 'Gross pay', 'NSSF (employee)', 'Taxable income', 'PAYE'],
                $details->map(fn ($d) => [$d->employee?->employee_no, $d->full_name, $d->employee?->tin, $d->gross_salary, $d->nssf, $d->amount_taxable, $d->paye]), [3, 4, 5, 6]),
            'nssf' => (function () use ($details, $member) {
                $no = $member(Nssf::class);

                return $this->table(['Employee no', 'Name', 'NSSF number', 'Gross pay', 'Employee', 'Employer', 'Total'],
                    $details->filter(fn ($d) => $d->nssf > 0 || $d->nssf_employer > 0)->map(fn ($d) => [$d->employee?->employee_no, $d->full_name, $no[$d->employee_id] ?? $d->employee?->nssf_no,
                        $d->gross_salary, $d->nssf, $d->nssf_employer, round($d->nssf + $d->nssf_employer, 2)]), [3, 4, 5, 6]);
            })(),
            'nhif' => (function () use ($details, $member) {
                $no = $member(Nhif::class);

                return $this->table(['Employee no', 'Name', 'NHIF number', 'Basic salary', 'Employee', 'Employer', 'Total'],
                    $details->filter(fn ($d) => $d->nhif > 0 || $d->nhif_employer > 0)->map(fn ($d) => [$d->employee?->employee_no, $d->full_name, $no[$d->employee_id] ?? $d->employee?->nhif_no,
                        $d->basic_salary, $d->nhif, $d->nhif_employer, round($d->nhif + $d->nhif_employer, 2)]), [3, 4, 5, 6]);
            })(),
            'heslb' => (function () use ($details, $member) {
                $no = $member(Heslb::class);

                return $this->table(['Employee no', 'Name', 'HESLB / index number', 'Basic salary', 'Repayment'],
                    $details->filter(fn ($d) => $d->heslb > 0)->map(fn ($d) => [$d->employee?->employee_no, $d->full_name, $no[$d->employee_id] ?? null, $d->basic_salary, $d->heslb]), [3, 4]);
            })(),
            'employer' => $this->table(['Employee no', 'Name', 'Gross pay', 'NSSF (employer)', 'NHIF (employer)', 'WCF', 'SDL', 'Total'],
                $details->map(fn ($d) => [$d->employee?->employee_no, $d->full_name, $d->gross_salary, $d->nssf_employer, $d->nhif_employer, $d->wcf, $d->sdl,
                    round($d->nssf_employer + $d->nhif_employer + $d->wcf + $d->sdl, 2)]), [2, 3, 4, 5, 6, 7]),
            default => $this->table(['Employee no', 'Name', 'Position', 'Basic', 'Allowances', 'Overtime', 'Relief', 'Gross', 'PAYE', 'NSSF', 'NHIF', 'HESLB', 'TUGHE', 'NIC', 'Fund', 'Loans', 'Advance', 'SACCOS', 'Deductions', 'Net pay'],
                $details->map(fn (PayrollSheetDetail $d) => [$d->employee?->employee_no, $d->full_name, $d->job_position, $d->basic_salary, $d->total_allowance, $d->total_overtime, $d->relief, $d->gross_salary,
                    $d->paye, $d->nssf, $d->nhif, $d->heslb, $d->tughe, $d->nic, $d->ef, $d->total_loan, $d->advance, $d->saccos_serving, $d->total_deduction, $d->net_salary]), range(3, 19)),
        } + ['title' => self::TYPES[$type] ?? $type];
    }

    /** @param list<int> $sum */
    private function table(array $columns, Collection $rows, array $sum): array
    {
        $rows = $rows->map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, array_values($r)))->values();
        $totals = array_map(fn ($i) => in_array($i, $sum, true) ? round((float) $rows->sum(fn ($r) => (float) $r[$i]), 2) : ($i === 1 ? 'Total' : null), array_keys($columns));

        return ['columns' => $columns, 'rows' => $rows->all(), 'totals' => $totals];
    }

    public function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $report['columns']);
        foreach ($report['rows'] as $row) {
            fputcsv($out, $row);
        }
        fputcsv($out, $report['totals']);
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }
}
