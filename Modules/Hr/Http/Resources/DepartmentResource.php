<?php

namespace Modules\Hr\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $head = $this->relationLoaded('headOfDepartment') ? $this->headOfDepartment : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'parent_id' => $this->parent_id,
            /** @var array{id: int, name: string}|null */
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            'description' => $this->description,
            'is_active' => $this->is_active,
            /** Current employees (not terminated). For a department this includes its sections. */
            'staff_count' => (int) ($this->parent_id ? ($this->section_staff_count ?? 0) : ($this->staff_count ?? 0)),
            /** @var array{id: int, employee_id: int, name: string, employee_no: string, position: string|null, photo_url: string|null, since: string|null}|null */
            'head' => $head && $head->employee ? [
                'id' => $head->id,
                'employee_id' => $head->employee->id,
                'name' => $head->employee->full_name,
                'employee_no' => $head->employee->employee_no,
                'position' => $head->employee->jobPosition?->title,
                'photo_url' => $head->employee->profile_image ? route('hr.employees.photo', $head->employee->id, false) : null,
                'since' => ($head->started_at ?? $head->created_at)?->toDateString(),
            ] : null,
            /** @var list<DepartmentResource> */
            'sections' => DepartmentResource::collection($this->whenLoaded('sections')),
            /** @var list<array{id: int, name: string, employee_no: string, from: string|null, to: string|null, reason: string|null, active: bool}> */
            'head_history' => $this->whenLoaded('heads', fn () => $this->heads->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->employee?->full_name ?? '—',
                'employee_no' => $h->employee?->employee_no ?? '',
                'from' => ($h->started_at ?? $h->created_at)?->toDateString(),
                'to' => $h->ended_at?->toDateString(),
                'reason' => $h->change_reason,
                'active' => $h->is_active,
            ])->values()->all()),
        ];
    }
}
