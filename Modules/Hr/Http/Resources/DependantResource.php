<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeDependant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin EmployeeDependant
 */
class DependantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** approved, pending (added by the employee, waiting for HR) or rejected */
            'approval_status' => $this->getAttribute('status') ?? 'approved',
            'decision_comment' => $this->decision_comment,
            'fullname' => $this->fullname,
            'gender' => $this->gender,
            'relationship' => $this->relationship,
            'relationship_label' => O::RELATIONSHIPS[$this->relationship] ?? $this->relationship,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->date_of_birth ? (int) $this->date_of_birth->diffInYears(now()) : null,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'is_next_of_kin' => $this->is_next_of_kin,
            /** @var array{name: string, url: string}|null */
            'birth_certificate' => EmployeeFiles::link($this->birth_certificate, $this->birth_certificate_name,
                route('hr.employees.dependants.file', [$this->employee_id, $this->id], false)),
        ];
    }
}
