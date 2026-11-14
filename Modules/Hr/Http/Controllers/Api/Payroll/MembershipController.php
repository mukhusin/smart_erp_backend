<?php

namespace Modules\Hr\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Relief;
use App\Models\SaccosMember;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Hr\Support\DeductionSchemes as S;
use Modules\Hr\Support\EmployeeScope;

/**
 * Members of each statutory scheme, SACCOS and reliefs across the company (within the user's employee scope).
 * Statutory memberships are saved per employee (PUT hr/employees/{employee}/deductions/{scheme}); SACCOS and reliefs here.
 *
 * @tags Payroll: Memberships
 */
class MembershipController extends Controller
{
    public const EXTRA = [
        'saccos' => ['label' => 'SACCOS', 'description' => 'Monthly SACCOS savings deducted from salary', 'type' => 'amount'],
        'relief' => ['label' => 'Relief', 'description' => 'Monthly relief added to pay (e.g. housing or transport relief)', 'type' => 'amount'],
    ];

    public function __construct(private readonly EmployeeScope $scope) {}

    /**
     * Members of a scheme: nssf, nhif, heslb, tughe, nic, fund, saccos or relief.
     *
     * `value` is the employee's rate (%) or monthly amount; `employer_value` the employer's rate where shared.
     *
     * @response array{data: list<array{employee: array{id: int, name: string, employee_no: string|null, department: string|null}, contribution_no: string|null, value: float, employer_value: float|null, is_active: bool, from_date: string|null, total_contribution: float|null, description: string|null}>, meta: array{scheme: string, label: string, description: string, type: string, total: float|null, number_label: string|null, number_required: bool, members: int, active: int}}
     */
    #[QueryParameter('scheme', type: 'string', required: true)]
    public function index(Request $request): JsonResponse
    {
        $scheme = $request->validate(['scheme' => ['required', Rule::in([...array_keys(S::SCHEMES), ...array_keys(self::EXTRA)])]])['scheme'];
        $model = match ($scheme) { 'saccos' => SaccosMember::class, 'relief' => Relief::class, default => S::get($scheme)['model'] };
        $rows = $model::with('employee.department')
            ->whereHas('employee', fn ($q) => $this->scope->apply($q->where('employment_status', '!=', 'terminated'), $request->user()))->get()
            ->sortBy(fn ($m) => $m->employee?->employee_no)->values();
        $info = self::EXTRA[$scheme] ?? null;
        $s = $info ? null : S::get($scheme);

        return response()->json([
            'data' => $rows->map(fn ($m) => [
                'employee' => ['id' => $m->employee->id, 'name' => $m->employee->full_name, 'employee_no' => $m->employee->employee_no, 'department' => $m->employee->department?->name],
                'contribution_no' => $m->contribution_no ?? null,
                'value' => (float) ($info ? $m->amount : $m->{S::valueField($scheme)}),
                'employer_value' => isset($m->employer_rate) ? (float) $m->employer_rate : null,
                'is_active' => (bool) $m->is_active,
                'from_date' => $scheme === 'saccos' ? $m->from_date?->toDateString() : null,
                'total_contribution' => $scheme === 'saccos' ? (float) $m->total_contribution : null,
                'description' => $scheme === 'relief' ? $m->description : null,
            ])->all(),
            'meta' => [
                'scheme' => $scheme, 'label' => $info['label'] ?? $s['label'], 'description' => $info['description'] ?? $s['description'],
                'type' => $info['type'] ?? $s['type'], 'total' => $s['total'] ?? null, 'number_label' => $s['number_label'] ?? null,
                'number_required' => ($s['number'] ?? null) === 'required', 'members' => $rows->count(), 'active' => $rows->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * Set an employee's SACCOS savings or relief (one each per employee).
     *
     * @response array{message: string}
     */
    public function update(Request $request, string $kind, Employee $employee): JsonResponse
    {
        abort_unless(isset(self::EXTRA[$kind]), 404);
        abort_unless($this->scope->allows($request->user(), $employee), 404);
        $d = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
            'from_date' => [$kind === 'saccos' ? 'nullable' : 'prohibited', 'date'],
            'description' => [$kind === 'relief' ? 'nullable' : 'prohibited', 'string', 'max:255'],
        ]);
        $fields = ['amount' => $d['amount'], 'is_active' => $d['is_active'] ?? true];
        if ($kind === 'saccos') {
            $fields['from_date'] = $d['from_date'] ?? now()->toDateString();
            SaccosMember::updateOrCreate(['employee_id' => $employee->id], $fields);
        } else {
            Relief::updateOrCreate(['employee_id' => $employee->id], $fields + ['description' => $d['description'] ?? null]);
        }

        return response()->json(['message' => self::EXTRA[$kind]['label'].' saved.']);
    }

    /** Remove an employee's SACCOS membership (only when nothing was contributed yet; otherwise switch it off) or relief. */
    public function destroy(Request $request, string $kind, Employee $employee): Response
    {
        abort_unless(isset(self::EXTRA[$kind]) && $this->scope->allows($request->user(), $employee), 404);
        if ($kind === 'saccos') {
            $member = SaccosMember::where('employee_id', $employee->id)->firstOrFail();
            abort_if((float) $member->total_contribution > 0 || \App\Models\SaccosContribution::where('saccos_member_id', $member->id)->exists(), 422,
                'This member has contributions. Switch the membership off instead.');
            $member->delete();
        } else {
            Relief::where('employee_id', $employee->id)->delete();
        }

        return response()->noContent();
    }
}
