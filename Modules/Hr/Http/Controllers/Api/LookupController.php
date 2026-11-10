<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
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
     * @response array{data: array{genders: list<array{value: string, label: string}>, employment_statuses: list<array{value: string, label: string}>, employment_types: list<array{value: string, label: string}>, categories: list<array{value: string, label: string}>, id_types: list<array{value: string, label: string}>, marital_statuses: list<array{value: string, label: string}>}}
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
     * Employee counts for the HR dashboard.
     *
     * @response array{data: array{total: int, probation: int, without_account: int, invited: int}}
     */
    public function summary(): JsonResponse
    {
        $active = Employee::where('employment_status', '!=', 'terminated');

        return response()->json(['data' => [
            'total' => (clone $active)->count(),
            'probation' => (clone $active)->where('employment_status', 'probation')->count(),
            'without_account' => (clone $active)->whereNull('user_id')->count(),
            'invited' => (clone $active)->whereHas('user', fn ($u) => $u->whereNull('email_verified_at'))->count(),
        ]]);
    }
}
