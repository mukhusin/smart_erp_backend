<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\ContractRenewal;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\JobPosition;
use App\Models\NatureOfContract;
use App\Models\ProfessionalLicence;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Modules\Hr\Support\EmployeeOptions;
use Modules\Hr\Support\EmployeeScope;

/**
 * Lists for HR forms and filters.
 *
 * @tags HR: Lookups
 */
class LookupController extends Controller
{
    /**
     * Employee field choices.
     *
     * @response array{data: array{genders: list<array{value: string, label: string}>, employment_statuses: list<array{value: string, label: string}>, employment_types: list<array{value: string, label: string}>, categories: list<array{value: string, label: string}>, id_types: list<array{value: string, label: string}>, marital_statuses: list<array{value: string, label: string}>, education_levels: list<array{value: string, label: string}>, document_types: list<array{value: string, label: string}>, relationships: list<array{value: string, label: string}>, contract_end_reasons: list<array{value: string, label: string}>}}
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => EmployeeOptions::all()]);
    }

    /**
     * Active branches.
     *
     * @response array{data: list<array{id: int, name: string, is_headquarters: bool}>}
     */
    public function branches(): JsonResponse
    {
        return response()->json(['data' => CompanyBranch::where('is_active', true)->orderByDesc('is_headquarters')->orderBy('name')->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'is_headquarters' => (bool) $b->is_headquarters])]);
    }

    /**
     * Search departments or sections.
     *
     * Without `parent_id`: departments (company-wide or of `branch_id`). With `parent_id`: that department's sections.
     *
     * @response array{data: list<array{id: int, name: string, short_name: string|null, branch: string|null}>}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('branch_id', type: 'integer')]
    #[QueryParameter('parent_id', type: 'integer')]
    public function departments(): JsonResponse
    {
        $search = trim((string) request('search'));
        $rows = Department::with('branch')->where('is_active', true)
            ->when(request()->filled('parent_id'), fn ($q) => $q->where('parent_id', request()->integer('parent_id')), fn ($q) => $q->whereNull('parent_id'))
            ->when(request()->filled('branch_id'), fn ($q) => $q->where(fn ($q) => $q->whereNull('company_branch_id')->orWhere('company_branch_id', request()->integer('branch_id'))))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%")))
            ->orderBy('name')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'short_name' => $d->short_name, 'branch' => $d->branch?->name])]);
    }

    /**
     * Search job positions.
     *
     * With `department_id`: that department's positions and positions not tied to a department.
     *
     * @response array{data: list<array{id: int, title: string, department: string|null, branch: string|null}>}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('department_id', type: 'integer')]
    public function positions(): JsonResponse
    {
        $search = trim((string) request('search'));
        $rows = JobPosition::with('department.branch')->where('is_active', true)
            ->when(request()->filled('department_id'), fn ($q) => $q->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', request()->integer('department_id'))))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderBy('title')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'department' => $p->department?->name, 'branch' => $p->department?->branch?->name])]);
    }

    /**
     * Active contract types.
     *
     * @response array{data: list<array{id: int, name: string, code: string|null, employment_type: string|null, has_annual_leave: bool}>}
     */
    public function contractTypes(): JsonResponse
    {
        return response()->json(['data' => NatureOfContract::where('is_active', true)->orderBy('contract_name')->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->contract_name, 'code' => $t->code, 'employment_type' => $t->employment_type, 'has_annual_leave' => (bool) $t->has_annual_leave])]);
    }

    /**
     * Active professional licence types.
     *
     * @response array{data: list<array{id: int, name: string, provider_name: string|null, short_name: string|null}>}
     */
    public function licenceTypes(): JsonResponse
    {
        return response()->json(['data' => ProfessionalLicence::where('is_active', true)->orderBy('name')->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'provider_name' => $l->provider_name, 'short_name' => $l->short_name])]);
    }

    /**
     * Counts for the HR dashboard.
     *
     * `contracts_ending`: active contracts ending within 90 days without a renewal waiting.
     * `licences_expiring`: licences of current staff expired or expiring within 90 days.
     * `placements_active`: interns and residents in an active period; `placements_ending`: ending within 30 days.
     * `employees`, `renewals`, `placements`, `departments`, `positions`: breakdowns for each HR page.
     * Counts cover only the employees the user may see (`scope`); department and position totals are organisation wide.
     *
     * @response array{data: array{total: int, probation: int, without_account: int, invited: int, without_contract: int, contracts_ending: int, licences_expiring: int, placements_active: int, placements_ending: int, employees: array{confirmed: int, probation: int, female: int, male: int, by_type: list<array{key: string, label: string, count: int}>}, renewals: array{pending: int, approved: int, renewed_this_year: int, rejected_this_year: int, ending_90_days: int}, placements: array{active: int, upcoming: int, ending_30_days: int, internship: int, residency: int}, departments: array{total: int, sections: int, staffed: int, top: list<array{id: int, name: string, count: int}>}, positions: array{total: int, filled: int, top: list<array{id: int, name: string, count: int}>}, movement: list<array{month: string, joined: int, left: int}>, scope: array{all: bool, label: string}}}
     */
    public function summary(EmployeeScope $scope): JsonResponse
    {
        $today = CarbonImmutable::today();
        $current = $scope->apply(Employee::where('employment_status', '!=', 'terminated'), request()->user());
        $active = $scope->apply(Employee::query()->active(), request()->user());
        $currentIds = (clone $current)->select('id');
        $staff = (clone $active)->with(['department:id,name', 'jobPosition:id,title'])->get(['id', 'gender', 'emp_type', 'employment_status', 'department_id', 'job_position_id']);
        $placements = EmployeePlacement::whereIn('employee_id', $currentIds);
        $renewals = ContractRenewal::whereIn('employee_id', $scope->apply(Employee::query(), request()->user())->select('id'));
        $contractsEnding = $scope->apply(Employee::query()->contractEnding(), request()->user())->count();
        $top = fn ($key, $name) => $staff->whereNotNull($key)->groupBy($key)->map(fn ($g, $id) => ['id' => (int) $id, 'name' => $name($g->first()) ?? '—', 'count' => $g->count()])
            ->sortByDesc('count')->take(5)->values()->all();

        return response()->json(['data' => [
            'total' => $staff->count(),
            'probation' => $staff->where('employment_status', 'probation')->count(),
            'without_account' => (clone $active)->whereNull('user_id')->count(),
            'invited' => $scope->apply(Employee::query()->invitePending(), request()->user())->count(),
            'without_contract' => $scope->apply(Employee::query()->withoutContract(), request()->user())->count(),
            'contracts_ending' => $contractsEnding,
            'licences_expiring' => $scope->apply(Employee::query()->licenceExpiring(), request()->user())->count(),
            'placements_active' => (clone $placements)->where('status', 'active')->count(),
            'placements_ending' => (clone $placements)->where('status', 'active')->whereDate('end_date', '<=', $today->addDays(30))->count(),
            'employees' => [
                'confirmed' => $staff->where('employment_status', 'confirmed')->count(),
                'probation' => $staff->where('employment_status', 'probation')->count(),
                'female' => $staff->where('gender', 'Female')->count(),
                'male' => $staff->where('gender', 'Male')->count(),
                'by_type' => collect(EmployeeOptions::EMPLOYMENT_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $staff->where('emp_type', $key)->count()])
                    ->filter(fn ($t) => $t['count'] > 0)->sortByDesc('count')->values()->all(),
            ],
            'renewals' => [
                'pending' => (clone $renewals)->where('status', 'pending')->count(),
                'approved' => (clone $renewals)->where('status', 'approved')->count(),
                'renewed_this_year' => (clone $renewals)->where('status', 'completed')->whereDate('updated_at', '>=', $today->startOfYear())->count(),
                'rejected_this_year' => (clone $renewals)->where('status', 'rejected')->whereDate('updated_at', '>=', $today->startOfYear())->count(),
                'ending_90_days' => $contractsEnding,
            ],
            'placements' => [
                'active' => (clone $placements)->where('status', 'active')->count(),
                'upcoming' => (clone $placements)->where('status', 'upcoming')->count(),
                'ending_30_days' => (clone $placements)->where('status', 'active')->whereDate('end_date', '<=', $today->addDays(30))->count(),
                'internship' => (clone $placements)->where('status', 'active')->where('type', 'internship')->count(),
                'residency' => (clone $placements)->where('status', 'active')->where('type', 'residency')->count(),
            ],
            'departments' => [
                'total' => Department::where('is_active', true)->whereNull('parent_id')->count(),
                'sections' => Department::where('is_active', true)->whereNotNull('parent_id')->count(),
                'staffed' => $staff->whereNotNull('department_id')->unique('department_id')->count(),
                'top' => $top('department_id', fn ($e) => $e->department?->name),
            ],
            'positions' => [
                'total' => JobPosition::where('is_active', true)->count(),
                'filled' => $staff->whereNotNull('job_position_id')->unique('job_position_id')->count(),
                'top' => $top('job_position_id', fn ($e) => $e->jobPosition?->title),
            ],
            'movement' => app(\Modules\Hr\Support\HrOverview::class)->movementFor(request()->user()),
            'scope' => $scope->describe(request()->user()),
        ]]);
    }
}
