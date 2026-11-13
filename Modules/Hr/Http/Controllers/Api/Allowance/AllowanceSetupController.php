<?php

namespace Modules\Hr\Http\Controllers\Api\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceCategory;
use App\Models\DailyAllowance;
use App\Models\Department;
use App\Models\DepartmentAllowance;
use App\Models\JobPosition;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Allowance categories (HRMS allowance categories) and rates (HRMS department allowances).
 *
 * @tags Allowance: Setup
 */
class AllowanceSetupController extends Controller
{
    /**
     * Allowance categories and the units to choose from.
     *
     * @response array{data: list<array{id: int, name: string, code: string, unit: string, unit_label: string, generation: string, deduct_leave: bool, other_absence: bool, taxable: bool, description: string|null, is_active: bool, rates_count: int}>, meta: array{units: array<string, string>}}
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => AllowanceCategory::withCount('departmentAllowances as rates_count')->orderByDesc('is_active')->orderBy('category')->get()->map(fn ($c) => self::category($c)),
            'meta' => ['units' => AllowanceCategory::UNITS],
        ]);
    }

    /**
     * Add an allowance category.
     *
     * @response array{data: array{id: int, name: string, code: string, unit: string, unit_label: string, generation: string, deduct_leave: bool, other_absence: bool, taxable: bool, description: string|null, is_active: bool, rates_count: int}}
     */
    public function storeCategory(Request $request): JsonResponse
    {
        return response()->json(['data' => self::category(AllowanceCategory::create($this->categoryData($request)))], 201);
    }

    /**
     * Update an allowance category.
     *
     * @response array{data: array{id: int, name: string, code: string, unit: string, unit_label: string, generation: string, deduct_leave: bool, other_absence: bool, taxable: bool, description: string|null, is_active: bool, rates_count: int}}
     */
    public function updateCategory(Request $request, AllowanceCategory $category): JsonResponse
    {
        $category->update($this->categoryData($request, $category));

        return response()->json(['data' => self::category($category->loadCount('departmentAllowances as rates_count'))]);
    }

    /** Delete a category that has no allowances paid; otherwise make it inactive. */
    public function destroyCategory(AllowanceCategory $category): Response
    {
        abort_if(DailyAllowance::withTrashed()->where('allowance_category_id', $category->id)->exists(), 422, 'Allowances have been paid in this category. Make it inactive instead.');
        $category->departmentAllowances()->delete();
        $category->delete();

        return response()->noContent();
    }

    /**
     * Allowance rates, with the departments, sections and positions to choose from.
     *
     * @response array{data: list<array{id: int, category: array{id: int, name: string, unit: string}|null, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, amount: float, is_active: bool}>, meta: array{departments: list<array{id: int, name: string, parent_id: int|null}>, positions: list<array{id: int, title: string, department_id: int|null}>}}
     */
    #[QueryParameter('category_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    public function rates(Request $request): JsonResponse
    {
        $f = $request->validate(['category_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer']]);
        $rows = DepartmentAllowance::with(['category', 'department', 'section', 'jobPosition'])
            ->when($f['category_id'] ?? null, fn ($q, $c) => $q->where('category_id', $c))
            ->when($f['department_id'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->get()->sortBy(fn ($r) => ($r->category?->category ?? '').' '.($r->department?->name ?? '').' '.($r->section?->name ?? '').' '.($r->jobPosition?->title ?? ''))->values();

        return response()->json([
            'data' => $rows->map(fn ($r) => self::rate($r)),
            'meta' => [
                'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'parent_id' => $d->parent_id]),
                'positions' => JobPosition::where('is_active', true)->orderBy('title')->get(['id', 'title', 'department_id'])->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'department_id' => $p->department_id]),
            ],
        ]);
    }

    /**
     * Add a rate.
     *
     * @response array{data: array{id: int, category: array{id: int, name: string, unit: string}|null, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, amount: float, is_active: bool}}
     */
    public function storeRate(Request $request): JsonResponse
    {
        $rate = DepartmentAllowance::create($this->rateData($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => self::rate($rate->load(['category', 'department', 'section', 'jobPosition']))], 201);
    }

    /**
     * Update a rate. Allowances already earned keep their amount.
     *
     * @response array{data: array{id: int, category: array{id: int, name: string, unit: string}|null, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, amount: float, is_active: bool}}
     */
    public function updateRate(Request $request, DepartmentAllowance $rate): JsonResponse
    {
        $rate->update($this->rateData($request, $rate) + ['last_updated_by' => $request->user()->employee?->id]);

        return response()->json(['data' => self::rate($rate->load(['category', 'department', 'section', 'jobPosition']))]);
    }

    /** Delete a rate. */
    public function destroyRate(DepartmentAllowance $rate): Response
    {
        $rate->delete();

        return response()->noContent();
    }

    public static function category(AllowanceCategory $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->category, 'code' => $c->code, 'unit' => $c->duration, 'unit_label' => AllowanceCategory::UNITS[$c->duration] ?? $c->duration,
            'generation' => $c->duration === 'case' ? 'claim' : $c->generation, 'deduct_leave' => (bool) $c->deduct_leave, 'other_absence' => (bool) $c->other_absence,
            'taxable' => (bool) $c->taxable, 'description' => $c->description, 'is_active' => (bool) $c->is_active, 'rates_count' => (int) ($c->rates_count ?? 0),
        ];
    }

    public static function rate(DepartmentAllowance $r): array
    {
        return [
            'id' => $r->id, 'category' => $r->category ? ['id' => $r->category->id, 'name' => $r->category->category, 'unit' => $r->category->duration] : null,
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'section' => $r->section ? ['id' => $r->section->id, 'name' => $r->section->name] : null,
            'position' => $r->jobPosition ? ['id' => $r->jobPosition->id, 'title' => $r->jobPosition->title] : null,
            'amount' => (float) $r->amount, 'is_active' => (bool) $r->is_active,
        ];
    }

    private function categoryData(Request $request, ?AllowanceCategory $category = null): array
    {
        $company = app(CompanyContext::class)->id();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('allowance_categories', 'category')->where('company_id', $company)->ignore($category?->id)],
            'code' => ['required', 'string', 'max:20', Rule::unique('allowance_categories', 'code')->where('company_id', $company)->ignore($category?->id)],
            /** month, day (working day), night (night shift), holiday (public holiday worked) or case (counted items). */
            'unit' => ['required', Rule::in(array_keys(AllowanceCategory::UNITS))],
            /** auto: worked out from the roster and working hours; claim: entered with a count and approved; assigned (monthly only): a fixed amount set per employee. Per-case allowances are always claimed. */
            'generation' => ['required', 'in:auto,claim,assigned'],
            /** Not paid on absent days (when attendance is used). */
            'other_absence' => ['boolean'],
            'taxable' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Another category has this name.', 'code.unique' => 'Another category has this code.']);
        if ($d['generation'] === 'assigned' && $d['unit'] !== 'month') {
            throw \Illuminate\Validation\ValidationException::withMessages(['generation' => 'Only monthly allowances can be assigned to employees.']);
        }

        return [
            'category' => trim($d['name']), 'code' => strtoupper(trim($d['code'])), 'duration' => $d['unit'], 'generation' => $d['unit'] === 'case' ? 'claim' : $d['generation'],
            'claimed' => $d['unit'] !== 'case' && $d['generation'] === 'auto', 'deduct_leave' => true, 'other_absence' => (bool) ($d['other_absence'] ?? false),
            'taxable' => (bool) ($d['taxable'] ?? true), 'description' => $d['description'] ?? null, 'is_active' => $d['is_active'] ?? $category?->is_active ?? true,
        ];
    }

    private function rateData(Request $request, ?DepartmentAllowance $rate = null): array
    {
        $company = app(CompanyContext::class)->id();
        $d = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('allowance_categories', 'id')->where('company_id', $company)],
            /** Department, or a section (its department is found). */
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            /** Empty: every position in the department or section. */
            'job_position_id' => ['nullable', 'integer', Rule::exists('job_positions', 'id')->where('company_id', $company)],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['boolean'],
        ]);
        $department = Department::findOrFail($d['department_id']);
        [$departmentId, $sectionId] = $department->parent_id ? [$department->parent_id, $department->id] : [$department->id, null];
        $duplicate = DepartmentAllowance::where('category_id', $d['category_id'])->where('department_id', $departmentId)
            ->where('section_id', $sectionId)->where('job_position_id', $d['job_position_id'] ?? null)->when($rate, fn ($q) => $q->whereKeyNot($rate->id))->exists();
        abort_if($duplicate, 422, 'This category already has a rate for this department and position.');

        return [
            'category_id' => $d['category_id'], 'department_id' => $departmentId, 'section_id' => $sectionId, 'job_position_id' => $d['job_position_id'] ?? null,
            'amount' => $d['amount'], 'is_active' => $d['is_active'] ?? $rate?->is_active ?? true,
        ];
    }
}
