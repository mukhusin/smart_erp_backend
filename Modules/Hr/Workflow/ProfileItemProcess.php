<?php

namespace Modules\Hr\Workflow;

use App\Models\EmployeeDependant;
use App\Models\EmployeeDocument;
use App\Models\EmployeeExperience;
use App\Models\EmployeeLicence;
use App\Models\EmployeeQualification;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageProfileItems;
use Modules\Hr\Support\EmployeeOptions as O;
use Modules\Hr\Support\ProfileSections;
use Modules\Workflow\Contracts\WorkflowProcess;

class ProfileItemProcess implements WorkflowProcess
{
    public const KEY = 'profile-update';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'hr';
    }

    public function name(): string
    {
        return 'Profile update by employee';
    }

    public function description(): string
    {
        return 'Qualifications, licences, experience, dependants and documents an employee adds in self-service. Without this workflow they are saved straight away.';
    }

    public function summary(Model $subject): array
    {
        $section = collect(ProfileSections::SECTIONS)->first(fn ($s) => $s['model'] === $subject::class);
        $lines = match (true) {
            $subject instanceof EmployeeQualification => [O::EDUCATION_LEVELS[$subject->education_level] ?? $subject->education_level, $subject->program, $subject->institution, $subject->end_year ? 'Completed '.$subject->end_year : null],
            $subject instanceof EmployeeLicence => [$subject->professionalLicence?->name, $subject->licence_no ? 'No. '.$subject->licence_no : null, 'Valid '.$subject->start_date?->format('d M Y').' – '.$subject->end_date?->format('d M Y')],
            $subject instanceof EmployeeExperience => [$subject->position.' at '.$subject->company_name, $subject->start_date?->format('M Y').' – '.($subject->end_date?->format('M Y') ?? 'present')],
            $subject instanceof EmployeeDependant => [$subject->fullname, O::RELATIONSHIPS[$subject->relationship] ?? $subject->relationship, $subject->is_next_of_kin ? 'Next of kin' : null],
            $subject instanceof EmployeeDocument => [O::DOCUMENT_TYPES[$subject->file_type] ?? $subject->file_type, $subject->description],
            default => [],
        };

        return [
            'title' => ($section['label'] ?? 'Profile record').' from '.($subject->employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter($lines)),
            'link' => $subject->employee_id ? '/hr/employees/'.$subject->employee_id : null,
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->employee?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageProfileItems::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageProfileItems::class)->reject($subject, $comment);
    }
}
