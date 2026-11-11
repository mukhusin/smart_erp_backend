<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeQualification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin EmployeeQualification
 */
class QualificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'education_level' => $this->education_level,
            'education_level_label' => O::EDUCATION_LEVELS[$this->education_level] ?? $this->education_level,
            'institution' => $this->institution,
            'program' => $this->program,
            'date_of_start' => $this->date_of_start?->toDateString(),
            'date_of_end' => $this->date_of_end?->toDateString(),
            'end_year' => $this->end_year !== null ? (int) $this->end_year : null,
            /** @var array{name: string, url: string}|null */
            'certificate' => EmployeeFiles::link($this->certificate, $this->certificate_name,
                route('hr.employees.qualifications.file', [$this->employee_id, $this->id], false)),
        ];
    }
}
