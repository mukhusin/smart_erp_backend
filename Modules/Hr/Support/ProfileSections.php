<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use App\Models\EmployeeDependant;
use App\Models\EmployeeDocument;
use App\Models\EmployeeExperience;
use App\Models\EmployeeLicence;
use App\Models\EmployeeQualification;
use Illuminate\Validation\Rule;
use Modules\Hr\Http\Requests\DocumentRequest;
use Modules\Hr\Http\Requests\ExperienceRequest;
use Modules\Hr\Http\Requests\LicenceRequest;
use Modules\Hr\Http\Requests\QualificationRequest;
use Modules\Hr\Http\Resources\DependantResource;
use Modules\Hr\Http\Resources\DocumentResource;
use Modules\Hr\Http\Resources\ExperienceResource;
use Modules\Hr\Http\Resources\LicenceResource;
use Modules\Hr\Http\Resources\QualificationResource;
use Modules\Hr\Support\EmployeeOptions as O;

/** Profile sections an employee can add to from self-service; HR approves each new record. */
final class ProfileSections
{
    /** @var array<string, array{label: string, model: class-string, relation: string, resource: class-string, fields: list<string>, file: array{input: string, path: string, name: string, folder: string}|null}> */
    public const SECTIONS = [
        'qualifications' => ['label' => 'Qualification', 'model' => EmployeeQualification::class, 'relation' => 'qualifications', 'resource' => QualificationResource::class,
            'fields' => ['education_level', 'institution', 'program', 'date_of_start', 'date_of_end', 'end_year'],
            'file' => ['input' => 'certificate', 'path' => 'certificate', 'name' => 'certificate_name', 'folder' => 'qualifications']],
        'licences' => ['label' => 'Professional licence', 'model' => EmployeeLicence::class, 'relation' => 'licences', 'resource' => LicenceResource::class,
            'fields' => ['professional_licence_id', 'licence_no', 'start_date', 'end_date'],
            'file' => ['input' => 'file', 'path' => 'file_path', 'name' => 'file_name', 'folder' => 'licences']],
        'experiences' => ['label' => 'Work experience', 'model' => EmployeeExperience::class, 'relation' => 'experiences', 'resource' => ExperienceResource::class,
            'fields' => ['company_name', 'position', 'start_date', 'end_date', 'salary', 'description'], 'file' => null],
        'dependants' => ['label' => 'Dependant', 'model' => EmployeeDependant::class, 'relation' => 'dependants', 'resource' => DependantResource::class,
            'fields' => ['fullname', 'gender', 'relationship', 'date_of_birth', 'phone', 'email', 'address', 'is_next_of_kin'],
            'file' => ['input' => 'birth_certificate', 'path' => 'birth_certificate', 'name' => 'birth_certificate_name', 'folder' => 'dependants']],
        'documents' => ['label' => 'Document', 'model' => EmployeeDocument::class, 'relation' => 'documents', 'resource' => DocumentResource::class,
            'fields' => ['file_type', 'description'], 'file' => ['input' => 'file', 'path' => 'file_path', 'name' => 'file_name', 'folder' => 'documents']],
    ];

    public static function rules(string $section, Employee $employee): array
    {
        $rules = match ($section) {
            'qualifications' => (new QualificationRequest)->rules(),
            'licences' => (new LicenceRequest)->rules(),
            'experiences' => (new ExperienceRequest)->rules(),
            'documents' => (new DocumentRequest)->rules(),
            'dependants' => self::dependantRules($employee),
        };

        return array_diff_key($rules, array_flip(['remove_certificate', 'remove_file', 'remove_birth_certificate']));
    }

    private static function dependantRules(Employee $employee): array
    {
        $relationship = ['required', O::keys(O::RELATIONSHIPS)];
        if (! in_array(request()->input('relationship'), O::MULTIPLE_RELATIONSHIPS, true)) {
            $relationship[] = Rule::unique('employee_dependants')->where('employee_id', $employee->id)->where('status', '!=', 'rejected');
        }

        return [
            'fullname' => ['required', 'string', 'min:3', 'max:150'],
            'gender' => ['required', O::keys(O::GENDERS)],
            'relationship' => $relationship,
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9\s\-\+\(\)]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:250'],
            'is_next_of_kin' => ['sometimes', 'boolean'],
            'birth_certificate' => ['nullable', 'file', ...EmployeeFiles::DOCUMENT_RULES],
        ];
    }
}
