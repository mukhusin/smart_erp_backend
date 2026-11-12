<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\LeaveTypeRequest;

/**
 * Leave types and their rules (HRMS Settings → Leaves).
 *
 * @tags Leave: Types
 */
class LeaveTypeController extends Controller
{
    /**
     * List leave types with the number of leave records of each, and the choices for the form.
     *
     * @response array{data: list<array{id: int, name: string, code: string, kind: string, kind_label: string, gender: string, description: string|null, with_pay: bool, max_days: int, day_count: string, day_count_label: string, limit_per: string, carry_over_days: int|null, notice_days: int, requires_document: bool, employee_types: list<string>|null, color: string|null, is_active: bool, leaves_count: int}>, meta: array{kinds: array<string, string>, day_counts: array<string, string>}}
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => LeaveType::withCount('leaves')->orderByDesc('is_active')->orderByRaw("kind = 'annual' desc")->orderBy('name')->get()->map(fn ($t) => self::row($t)),
            'meta' => ['kinds' => LeaveType::KINDS, 'day_counts' => LeaveType::DAY_COUNTS],
        ]);
    }

    /**
     * Add a leave type.
     *
     * @response array{data: array{id: int, name: string, code: string, kind: string, kind_label: string, gender: string, description: string|null, with_pay: bool, max_days: int, day_count: string, day_count_label: string, limit_per: string, carry_over_days: int|null, notice_days: int, requires_document: bool, employee_types: list<string>|null, color: string|null, is_active: bool, leaves_count: int}}
     */
    public function store(LeaveTypeRequest $request): JsonResponse
    {
        return response()->json(['data' => self::row(LeaveType::create($this->data($request)))], 201);
    }

    /**
     * Update a leave type. Leave already taken keeps its days.
     *
     * @response array{data: array{id: int, name: string, code: string, kind: string, kind_label: string, gender: string, description: string|null, with_pay: bool, max_days: int, day_count: string, day_count_label: string, limit_per: string, carry_over_days: int|null, notice_days: int, requires_document: bool, employee_types: list<string>|null, color: string|null, is_active: bool, leaves_count: int}}
     */
    public function update(LeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $leaveType->update($this->data($request));

        return response()->json(['data' => self::row($leaveType->loadCount('leaves'))]);
    }

    /** Delete a leave type. Types with leave records cannot be deleted; make them inactive instead. */
    public function destroy(LeaveType $leaveType): Response
    {
        abort_if($leaveType->leaves()->exists(), 422, 'Leave has been taken with this type. Make it inactive instead.');
        $leaveType->delete();

        return response()->noContent();
    }

    public static function row(LeaveType $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'kind' => $t->kind, 'kind_label' => LeaveType::KINDS[$t->kind] ?? $t->kind,
            'gender' => $t->gender, 'description' => $t->description, 'with_pay' => (bool) $t->with_pay, 'max_days' => (int) $t->max_days,
            'day_count' => $t->day_count, 'day_count_label' => LeaveType::DAY_COUNTS[$t->day_count] ?? $t->day_count, 'limit_per' => $t->limit_per,
            'carry_over_days' => $t->carry_over_days, 'notice_days' => (int) $t->notice_days, 'requires_document' => (bool) $t->requires_document,
            'employee_types' => $t->employee_types ?: null, 'color' => $t->color, 'is_active' => (bool) $t->is_active, 'leaves_count' => (int) ($t->leaves_count ?? 0),
        ];
    }

    private function data(LeaveTypeRequest $request): array
    {
        $d = $request->validated();
        $yearly = $d['limit_per'] === 'year';

        return [
            'name' => trim($d['name']), 'code' => strtoupper(trim($d['code'])), 'kind' => $d['kind'], 'gender' => $d['gender'], 'description' => $d['description'] ?? null,
            'with_pay' => $d['kind'] === 'unpaid' ? false : (bool) $d['with_pay'], 'max_days' => (int) $d['max_days'], 'day_count' => $d['day_count'],
            'limit_per' => $d['limit_per'], 'carry_over_days' => $yearly ? ($d['carry_over_days'] ?? null) : null, 'notice_days' => (int) ($d['notice_days'] ?? 0),
            'requires_document' => (bool) ($d['requires_document'] ?? false), 'employee_types' => ($d['employee_types'] ?? []) ?: null,
            'color' => $d['color'] ?? null, 'is_active' => $d['is_active'] ?? true,
        ];
    }
}
