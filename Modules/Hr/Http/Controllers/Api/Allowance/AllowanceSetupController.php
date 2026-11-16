<?php

namespace Modules\Hr\Http\Controllers\Api\Allowance;

use Modules\Hr\Http\Requests\AllowanceRateRequest;
use Modules\Hr\Http\Requests\AllowanceCategoryRequest;
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
     * @response array{data: list<array{id: int, name: string, code: string, unit: string, unit_label: string, generation: string, activity_source: string|null, activity_config: array{url: string|null, params: array<string, string>, has_token: bool, count_path: string|null, list_path: string|null, count_field: string|null, id_field: string|null, ids: list<string>}|null, deduct_leave: bool, other_absence: bool, taxable: bool, description: string|null, is_active: bool, rates_count: int}>, meta: array{units: array<string, string>, sources: array<string, string>}}
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => AllowanceCategory::withCount('departmentAllowances as rates_count')->orderByDesc('is_active')->orderBy('category')->get()->map(fn ($c) => self::category($c)),
            'meta' => ['units' => AllowanceCategory::UNITS, 'sources' => AllowanceCategory::SOURCES],
        ]);
    }

    /**
     * Add an allowance category.
     *
     * @response array{data: array{id: int, name: string, code: string, unit: string, unit_label: string, generation: string, activity_source: string|null, activity_config: array{url: string|null, params: array<string, string>, has_token: bool, count_path: string|null, list_path: string|null, count_field: string|null, id_field: string|null, ids: list<string>}|null, deduct_leave: bool, other_absence: bool, taxable: bool, description: string|null, is_active: bool, rates_count: int}}
     */
    public function storeCategory(AllowanceCategoryRequest $request): JsonResponse
    {
        return response()->json(['data' => self::category(AllowanceCategory::create($this->categoryData($request)))], 201);
    }

    /**
     * Update an allowance category.
     *
     * @response array{data: array{id: int, name: string, code: string, unit: string, unit_label: string, generation: string, activity_source: string|null, activity_config: array{url: string|null, params: array<string, string>, has_token: bool, count_path: string|null, list_path: string|null, count_field: string|null, id_field: string|null, ids: list<string>}|null, deduct_leave: bool, other_absence: bool, taxable: bool, description: string|null, is_active: bool, rates_count: int}}
     */
    public function updateCategory(AllowanceCategoryRequest $request, AllowanceCategory $category): JsonResponse
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
    public function storeRate(AllowanceRateRequest $request): JsonResponse
    {
        $rate = DepartmentAllowance::create($this->rateData($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => self::rate($rate->load(['category', 'department', 'section', 'jobPosition']))], 201);
    }

    /**
     * Update a rate. Allowances already earned keep their amount.
     *
     * @response array{data: array{id: int, category: array{id: int, name: string, unit: string}|null, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, amount: float, is_active: bool}}
     */
    public function updateRate(AllowanceRateRequest $request, DepartmentAllowance $rate): JsonResponse
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
            'generation' => $c->duration === 'case' && $c->generation !== 'activity' ? 'claim' : $c->generation,
            'activity_source' => $c->activity() ? ($c->activity_source ?? 'manual') : null,
            'activity_config' => $c->activity() && $c->activity_source === 'rest' ? self::sourceConfig($c->activity_config ?? []) : null,
            'deduct_leave' => (bool) $c->deduct_leave, 'other_absence' => (bool) $c->other_absence,
            'taxable' => (bool) $c->taxable, 'description' => $c->description, 'is_active' => (bool) $c->is_active, 'rates_count' => (int) ($c->rates_count ?? 0),
        ];
    }

    private static function sourceConfig(array $c): array
    {
        return [
            'url' => $c['url'] ?? null, 'params' => (object) ($c['params'] ?? []), 'has_token' => ! empty($c['token']), 'count_path' => $c['count_path'] ?? null,
            'list_path' => $c['list_path'] ?? null, 'count_field' => $c['count_field'] ?? null, 'id_field' => $c['id_field'] ?? null, 'ids' => array_map('strval', $c['ids'] ?? []),
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
        $d = $request->validated();
        if ($d['generation'] === 'assigned' && $d['unit'] !== 'month') {
            throw \Illuminate\Validation\ValidationException::withMessages(['generation' => 'Only monthly allowances can be assigned to employees.']);
        }
        $activity = $d['unit'] === 'case' && $d['generation'] === 'activity';
        $source = $activity ? ($d['activity_source'] ?? 'manual') : null;
        $config = null;
        if ($source === 'rest') {
            $in = $d['activity_config'] ?? [];
            if (empty($in['count_path']) && (empty($in['list_path']) || empty($in['count_field']))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['activity_config.count_path' => 'Say where the number is: a path to it, or a list and the field to add up.']);
            }
            $saved = $category?->activity_config ?? [];
            $token = ! empty($in['clear_token']) ? null : (($in['token'] ?? '') !== '' ? $in['token'] : ($saved['token'] ?? null));
            $config = array_filter([
                'url' => $in['url'], 'params' => array_map(fn ($v) => (string) $v, $in['params'] ?? []), 'token' => $token,
                'count_path' => $in['count_path'] ?? null, 'list_path' => $in['list_path'] ?? null, 'count_field' => $in['count_field'] ?? null,
                'id_field' => $in['id_field'] ?? null, 'ids' => array_values(array_unique(array_map('trim', $in['ids'] ?? []))),
            ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        }

        return [
            'category' => trim($d['name']), 'code' => strtoupper(trim($d['code'])), 'duration' => $d['unit'], 'generation' => $d['unit'] === 'case' ? ($activity ? 'activity' : 'claim') : $d['generation'],
            'activity_source' => $source, 'activity_config' => $config,
            'claimed' => $d['unit'] !== 'case' && $d['generation'] === 'auto', 'deduct_leave' => true, 'other_absence' => (bool) ($d['other_absence'] ?? false),
            'taxable' => (bool) ($d['taxable'] ?? true), 'description' => $d['description'] ?? null, 'is_active' => $d['is_active'] ?? $category?->is_active ?? true,
        ];
    }

    private function rateData(Request $request, ?DepartmentAllowance $rate = null): array
    {
        $company = app(CompanyContext::class)->id();
        $d = $request->validated();
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
