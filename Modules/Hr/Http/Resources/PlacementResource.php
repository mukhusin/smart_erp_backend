<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeePlacement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeePlacement
 */
class PlacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::today();
        $open = in_array($this->status, ['active', 'upcoming'], true);
        $total = max(1, $this->start_date->diffInDays($this->end_date));

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => EmployeePlacement::TYPES[$this->type] ?? $this->type,
            'year' => $this->year,
            /** Months. */
            'period' => $this->period,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            /** `upcoming`, `active`, `ended` or `cancelled`. */
            'status' => $this->status,
            'status_label' => EmployeePlacement::STATUSES[$this->status] ?? $this->status,
            'days_left' => $open ? (int) $today->diffInDays($this->end_date, false) : null,
            'progress' => $this->status === 'active' ? (int) min(100, max(0, round($this->start_date->diffInDays($today) / $total * 100))) : null,
            'comment' => $this->comment,
            /** @var array{id: int, name: string, employee_no: string, department: string|null, position: string|null, photo_url: string|null}|null */
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name,
                'employee_no' => $this->employee->employee_no,
                'department' => $this->employee->department?->name,
                'position' => $this->employee->jobPosition?->title,
                'photo_url' => $this->employee->profile_image ? route('hr.employees.photo', $this->employee->id, false) : null,
            ]),
        ];
    }
}
