<?php

namespace Modules\Hr\Support;

use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use Illuminate\Support\Collection;

/**
 * Compares two payroll sheets employee by employee and item by item, so a payroll can be checked against the month before
 * (and against HRMS during the parallel run). Items are matched on their code and label without the counts in brackets.
 */
class PayrollComparison
{
    private const GROUPS = ['earning' => 'Earnings', 'deduction' => 'Deductions', 'employer' => 'Employer costs'];

    public function previous(PayrollSheet $sheet): ?PayrollSheet
    {
        $key = sprintf('%04d%02d', (int) $sheet->year, (int) $sheet->month);

        return PayrollSheet::query()->whereKeyNot($sheet->id)->get()
            ->filter(fn ($s) => sprintf('%04d%02d', (int) $s->year, (int) $s->month) < $key)
            ->sortByDesc(fn ($s) => [sprintf('%04d%02d', (int) $s->year, (int) $s->month), $s->status === 'approved' ? 1 : 0, $s->id])
            ->first();
    }

    public function compare(PayrollSheet $current, ?PayrollSheet $previous): array
    {
        $cur = $this->details($current);
        $prev = $previous ? $this->details($previous) : collect();
        $employees = $cur->keys()->merge($prev->keys())->unique();

        $rows = $employees->map(function ($id) use ($cur, $prev) {
            $c = $cur[$id] ?? null;
            $p = $prev[$id] ?? null;
            $changes = $this->changes($p ? $this->items($p) : [], $c ? $this->items($c) : []);
            $netDiff = round(($c?->net_salary ?? 0) - ($p?->net_salary ?? 0), 2);
            $status = ! $p ? 'new' : (! $c ? 'left' : (abs($netDiff) >= 0.5 || $changes !== [] ? 'changed' : 'same'));
            $any = $c ?? $p;

            return [
                'employee_id' => (int) $id,
                'employee_no' => $any->employee?->employee_no,
                'name' => $any->full_name,
                'department' => $any->employee?->department?->name,
                'status' => $status,
                'previous_gross' => round((float) ($p?->gross_salary ?? 0), 2),
                'current_gross' => round((float) ($c?->gross_salary ?? 0), 2),
                'previous_net' => round((float) ($p?->net_salary ?? 0), 2),
                'current_net' => round((float) ($c?->net_salary ?? 0), 2),
                'net_difference' => $netDiff,
                'reason' => $this->reason($status, $changes),
                'changes' => $changes,
            ];
        })->sortBy([fn ($a, $b) => $this->rank($a['status']) <=> $this->rank($b['status']), fn ($a, $b) => abs($b['net_difference']) <=> abs($a['net_difference'])])->values();

        return [
            'current' => $this->sheetInfo($current, $cur),
            'previous' => $previous ? $this->sheetInfo($previous, $prev) : null,
            'counts' => collect(['new', 'left', 'changed', 'same'])->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])->all(),
            'items' => $this->itemTotals($cur, $prev),
            'employees' => $rows->all(),
        ];
    }

    /** @return Collection<int, PayrollSheetDetail> keyed by employee id */
    private function details(PayrollSheet $sheet): Collection
    {
        return PayrollSheetDetail::with('employee:id,employee_no,department_id', 'employee.department:id,name')
            ->where('payroll_sheet_id', $sheet->id)->get()->keyBy('employee_id');
    }

    /** @return array<string, array{group: string, label: string, amount: float}> */
    private function items(PayrollSheetDetail $detail): array
    {
        $items = [];
        foreach ($detail->lines ?? [] as $line) {
            $label = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($line['label'] ?? '')));
            $key = ($line['group'] ?? 'earning').'|'.($line['code'] ?? '').'|'.mb_strtolower($label);
            $items[$key] ??= ['group' => $line['group'] ?? 'earning', 'label' => $label, 'amount' => 0.0];
            $items[$key]['amount'] += (float) ($line['amount'] ?? 0);
        }

        return $items;
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $p = round($before[$key]['amount'] ?? 0, 2);
            $c = round($after[$key]['amount'] ?? 0, 2);
            if (abs($c - $p) >= 0.5) {
                $item = $after[$key] ?? $before[$key];
                $changes[] = ['group' => $item['group'], 'label' => $item['label'], 'previous' => $p, 'current' => $c, 'difference' => round($c - $p, 2)];
            }
        }
        usort($changes, fn ($a, $b) => abs($b['difference']) <=> abs($a['difference']));

        return $changes;
    }

    private function reason(string $status, array $changes): string
    {
        if ($status === 'new') {
            return 'New in this payroll';
        }
        if ($status === 'left') {
            return 'Not in this payroll';
        }
        $text = collect($changes)->where('group', '!=', 'employer')->take(3)
            ->map(fn ($c) => $c['label'].' '.($c['difference'] > 0 ? '+' : '−').number_format(abs($c['difference'])))->implode('; ');

        return $text ?: ($status === 'same' ? 'No change' : 'Employer costs only');
    }

    private function itemTotals(Collection $cur, Collection $prev): array
    {
        $sum = function (Collection $details) {
            $totals = [];
            foreach ($details as $d) {
                foreach ($this->items($d) as $key => $item) {
                    $totals[$key] ??= ['group' => $item['group'], 'label' => $item['label'], 'amount' => 0.0, 'employees' => 0];
                    $totals[$key]['amount'] += $item['amount'];
                    $totals[$key]['employees']++;
                }
            }

            return $totals;
        };
        $c = $sum($cur);
        $p = $sum($prev);

        return collect(array_unique(array_merge(array_keys($c), array_keys($p))))->map(fn ($key) => [
            'group' => ($c[$key] ?? $p[$key])['group'],
            'group_label' => self::GROUPS[($c[$key] ?? $p[$key])['group']] ?? ucfirst(($c[$key] ?? $p[$key])['group']),
            'label' => ($c[$key] ?? $p[$key])['label'],
            'previous' => round($p[$key]['amount'] ?? 0, 2),
            'current' => round($c[$key]['amount'] ?? 0, 2),
            'difference' => round(($c[$key]['amount'] ?? 0) - ($p[$key]['amount'] ?? 0), 2),
            'previous_employees' => $p[$key]['employees'] ?? 0,
            'current_employees' => $c[$key]['employees'] ?? 0,
        ])->sortBy([fn ($a, $b) => array_search($a['group'], array_keys(self::GROUPS)) <=> array_search($b['group'], array_keys(self::GROUPS)),
            fn ($a, $b) => abs($b['difference']) <=> abs($a['difference'])])->values()->all();
    }

    private function sheetInfo(PayrollSheet $sheet, Collection $details): array
    {
        return [
            'id' => $sheet->id, 'name' => $sheet->name, 'period' => sprintf('%04d-%02d', (int) $sheet->year, (int) $sheet->month),
            'status' => $sheet->status, 'status_label' => PayrollSheet::STATUS_LABELS[$sheet->status] ?? $sheet->status,
            'employees' => $details->count(),
            'gross' => round((float) $details->sum('gross_salary'), 2),
            'deductions' => round((float) $details->sum('total_deduction'), 2),
            'net' => round((float) $details->sum('net_salary'), 2),
            'employer' => round((float) $details->sum(fn ($d) => collect($d->lines ?? [])->where('group', 'employer')->sum('amount')), 2),
        ];
    }

    private function rank(string $status): int
    {
        return ['changed' => 0, 'new' => 1, 'left' => 2, 'same' => 3][$status];
    }
}
