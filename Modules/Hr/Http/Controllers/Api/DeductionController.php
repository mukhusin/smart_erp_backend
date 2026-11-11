<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Support\DeductionSchemes as S;

/**
 * Statutory memberships and fixed deductions of an employee (NSSF, NHIF, HESLB, TUGHE, NIC, employee fund).
 *
 * @tags HR: Deductions
 */
class DeductionController extends Controller
{
    /**
     * All schemes with the employee's membership, and the monthly estimate from the active contract's basic salary.
     *
     * @response array{data: list<array{scheme: string, label: string, description: string, type: string, total: float|null, number_label: string|null, number_required: bool, member: bool, contribution_no: string|null, value: float|null, employer_value: float|null, is_active: bool, estimate: array{employee: float, employer: float}|null}>, meta: array{basic_salary: float|null}}
     */
    public function index(Employee $employee): JsonResponse
    {
        $basic = $employee->activeContract()->value('basic_salary');
        $basic = $basic !== null ? (float) $basic : null;

        $rows = collect(S::SCHEMES)->map(function (array $s, string $key) use ($employee, $basic) {
            $member = $s['model']::where('employee_id', $employee->id)->first();
            $value = $member ? (float) $member->{S::valueField($key)} : null;
            $employer = $member && $s['total'] !== null ? (float) ($member->employer_rate ?? $s['total'] - $value) : null;

            return [
                'scheme' => $key,
                'label' => $s['label'],
                'description' => $s['description'],
                /** `percent` of basic salary or fixed `amount` per month. */
                'type' => $s['type'],
                /** Total percent shared by employee and employer (NSSF 20, NHIF 6). */
                'total' => $s['total'],
                'number_label' => $s['number_label'],
                'number_required' => $s['number'] === 'required',
                'member' => (bool) $member,
                'contribution_no' => $member?->contribution_no,
                'value' => $value,
                'employer_value' => $employer,
                'is_active' => (bool) ($member?->is_active ?? false),
                'estimate' => $member && $member->is_active ? $this->estimate($s['type'], $value, $employer, $basic) : null,
            ];
        })->values();

        return response()->json(['data' => $rows, 'meta' => ['basic_salary' => $basic]]);
    }

    /**
     * Add or update the employee's membership of a scheme.
     *
     * `value`: the employee's percent of basic salary (percent schemes) or the monthly amount (NIC, employee fund).
     * For NSSF and NHIF the employer pays the rest of the total.
     *
     * @response array{message: string}
     */
    public function update(Request $request, Employee $employee, string $scheme): JsonResponse
    {
        $s = S::get($scheme);
        $data = $request->validate([
            'contribution_no' => array_values(array_filter([$s['number'] === 'required' ? 'required' : 'nullable', 'string', 'max:50'])),
            'value' => ['required', 'numeric', 'min:0', $s['type'] === 'percent' ? 'max:'.($s['total'] ?? 100) : 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'contribution_no.required' => "Enter the {$s['label']} ".strtolower((string) $s['number_label']).'.',
            'value.max' => $s['total'] ? "The employee's share cannot be more than {$s['total']}%." : 'The value is too high.',
        ]);

        $number = isset($data['contribution_no']) ? trim($data['contribution_no']) : null;
        if ($s['unique'] && $number) {
            $taken = $s['model']::where('contribution_no', $number)->where('employee_id', '!=', $employee->id)
                ->whereIn('employee_id', Employee::withTrashed()->select('id'))->with('employee')->first();
            if ($taken) {
                throw ValidationException::withMessages(['contribution_no' => "This number belongs to {$taken->employee?->full_name} ({$taken->employee?->employee_no})."]);
            }
        }

        DB::transaction(function () use ($s, $scheme, $employee, $data, $number) {
            $fields = [S::valueField($scheme) => $data['value'], 'is_active' => $data['is_active'] ?? true];
            if ($s['number'] !== null) {
                $fields['contribution_no'] = $number;
            }
            if ($s['total'] !== null) {
                $fields['employer_rate'] = $s['total'] - (float) $data['value'];
            }
            $s['model']::updateOrCreate(['employee_id' => $employee->id], $fields);
            if ($s['employee_field'] && $number) {
                $employee->update([$s['employee_field'] => $number]);
            }
        });

        return response()->json(['message' => "{$s['label']} saved."]);
    }

    /**
     * Remove the employee from a scheme.
     */
    public function destroy(Employee $employee, string $scheme): Response
    {
        S::get($scheme)['model']::where('employee_id', $employee->id)->delete();

        return response()->noContent();
    }

    /** @return array{employee: float, employer: float}|null */
    private function estimate(string $type, ?float $value, ?float $employer, ?float $basic): ?array
    {
        if ($type === 'amount') {
            return ['employee' => round((float) $value, 2), 'employer' => 0.0];
        }
        if ($basic === null) {
            return null;
        }

        return ['employee' => round($basic * (float) $value / 100, 2), 'employer' => round($basic * (float) $employer / 100, 2)];
    }
}
