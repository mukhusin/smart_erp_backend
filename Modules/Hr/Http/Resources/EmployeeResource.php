<?php

namespace Modules\Hr\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'name' => $this->full_name,
            'fname' => $this->fname,
            'mname' => $this->mname,
            'lname' => $this->lname,
            'gender' => $this->gender,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'photo_url' => $this->profile_image
                ? route('hr.employees.photo', $this->id, false) . '?v=' . substr(md5($this->profile_image), 0, 8)
                : null,
            /** @var array{id: int, name: string}|null */
            'branch' => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null,
            /** @var array{id: int, name: string}|null */
            'department' => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null,
            /** @var array{id: int, name: string}|null */
            'section' => $this->section ? ['id' => $this->section->id, 'name' => $this->section->name] : null,
            /** @var array{id: int, title: string}|null */
            'position' => $this->jobPosition ? ['id' => $this->jobPosition->id, 'title' => $this->jobPosition->title] : null,
            'emp_type' => $this->emp_type,
            'emp_type_label' => O::EMPLOYMENT_TYPES[$this->emp_type] ?? $this->emp_type,
            'employment_status' => $this->employment_status,
            'employment_status_label' => O::EMPLOYMENT_STATUSES[$this->employment_status] ?? $this->employment_status,
            'employment_date' => $this->employment_date?->toDateString(),
            /** @var array{id: int, username: string, is_active: bool, has_set_password: bool}|null */
            'account' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'is_active' => (bool) $user->is_active,
                'has_set_password' => $user->email_verified_at !== null,
            ] : null,
            'details' => [
                'date_of_birth' => $this->date_of_birth?->toDateString(),
                'place_of_birth' => $this->place_of_birth,
                'nationality' => $this->nationality,
                'marital_status' => $this->marital_status,
                'marital_status_label' => O::MARITAL_STATUSES[$this->marital_status] ?? $this->marital_status,
                'id_type' => $this->id_type,
                'id_type_label' => O::ID_TYPES[$this->id_type] ?? $this->id_type,
                'id_number' => $this->id_number,
                'tin' => $this->tin,
                'home_address' => $this->home_address,
                'emp_title' => $this->emp_title,
                'emp_category' => $this->emp_category,
                'place_of_recruitment' => $this->place_of_recruitment,
                'probation_start' => $this->probation_start?->toDateString(),
                'probation_period' => $this->probation_period,
                'probation_end' => $this->probation_end?->toDateString(),
                'termination_date' => $this->termination_date?->toDateString(),
                'nssf_no' => $this->nssf_no,
                'nhif_no' => $this->nhif_no,
                'wcf_no' => $this->wcf_no,
                'has_annual_leave' => (bool) ($this->has_annual_leave ?? true),
            ],
            /** Active contract (on the profile). */
            'contract' => $this->whenLoaded('activeContract', fn () => $this->activeContract ? new ContractResource($this->activeContract) : null),
            /** Contract waiting to start (on the profile). */
            'waiting_contract' => $this->whenLoaded('waitingContract', fn () => $this->waitingContract ? new ContractResource($this->waitingContract) : null),
            /** Current or next internship/residency period (on the profile). */
            'placement' => $this->whenLoaded('currentPlacement', fn () => $this->currentPlacement ? new PlacementResource($this->currentPlacement) : null),
            /**
             * Number of records per profile section (on the profile).
             *
             * @var array{contracts: int, placements: int, qualifications: int, licences: int, experiences: int, documents: int, dependants: int, next_of_kin: int}
             */
            'counts' => $this->when(isset($this->contracts_count), fn () => [
                'contracts' => (int) $this->contracts_count,
                'placements' => (int) ($this->placements_count ?? 0),
                'qualifications' => (int) $this->qualifications_count,
                'licences' => (int) $this->licences_count,
                'experiences' => (int) $this->experiences_count,
                'documents' => (int) $this->documents_count,
                'dependants' => (int) $this->dependants_count,
                'next_of_kin' => (int) $this->next_of_kin_count,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
