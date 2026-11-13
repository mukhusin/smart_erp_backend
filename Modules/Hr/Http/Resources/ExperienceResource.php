<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeExperience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeExperience
 */
class ExperienceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** approved, pending (added by the employee, waiting for HR) or rejected */
            'approval_status' => $this->getAttribute('status') ?? 'approved',
            'decision_comment' => $this->decision_comment,
            'company_name' => $this->company_name,
            'position' => $this->position,
            'start_date' => $this->start_date->toDateString(),
            /** Null = still working there. */
            'end_date' => $this->end_date?->toDateString(),
            'months' => (int) $this->start_date->diffInMonths($this->end_date ?? now()),
            'salary' => $this->salary !== null ? (float) $this->salary : null,
            'description' => $this->description,
        ];
    }
}
