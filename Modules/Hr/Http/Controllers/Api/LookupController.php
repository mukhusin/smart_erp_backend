<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLicence;
use App\Models\JobPosition;
use App\Models\NatureOfContract;
use App\Models\ProfessionalLicence;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Modules\Hr\Support\EmployeeOptions;

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
     * @response array{data: list<array{id: int, title: string, department: string|null}>}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('department_id', type: 'integer')]
    public function positions(): JsonResponse
    {
        $search = trim((string) request('search'));
        $rows = JobPosition::with('department')->where('is_active', true)
            ->when(request()->filled('department_id'), fn ($q) => $q->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', request()->integer('department_id'))))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderBy('title')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'department' => $p->department?->name])]);
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
     *
     * @response array{data: array{total: int, probation: int, without_account: int, invited: int, without_contract: int, contracts_ending: int, licences_expiring: int}}
     */
    public function summary(): JsonResponse
    {
        $active = Employee::where('employment_status', '!=', 'terminated');
        $ids = (clone $active)->select('id');
        $soon = CarbonImmutable::today()->addDays(EmployeeOptions::RENEWAL_WINDOW_DAYS)->toDateString();

        return response()->json(['data' => [
            'total' => (clone $active)->count(),
            'probation' => (clone $active)->where('employment_status', 'probation')->count(),
            'without_account' => (clone $active)->whereNull('user_id')->count(),
            'invited' => (clone $active)->whereHas('user', fn ($u) => $u->whereNull('email_verified_at'))->count(),
            'without_contract' => (clone $active)->whereDoesntHave('contracts', fn ($q) => $q->whereIn('status', ['active', 'waiting']))->count(),
            'contracts_ending' => EmployeeContract::whereIn('employee_id', $ids)->where('is_active', true)->whereDate('end_date', '<=', $soon)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_contracts as w')->whereColumn('w.employee_id', 'employee_contracts.employee_id')
                    ->where('w.status', 'waiting')->whereNull('w.deleted_at'))->count(),
            'licences_expiring' => EmployeeLicence::whereIn('employee_id', $ids)->whereDate('end_date', '<=', $soon)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_licences as n')->whereColumn('n.employee_id', 'employee_licences.employee_id')
                    ->whereColumn('n.professional_licence_id', 'employee_licences.professional_licence_id')->whereColumn('n.end_date', '>', 'employee_licences.end_date'))->count(),
        ]]);
    }
}
