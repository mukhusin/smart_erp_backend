<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ProfessionalLicence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Modules\Hr\Actions\ManageProfileItems;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;
use Modules\Hr\Support\ProfileSections;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Self service: Profile
 */
class SelfProfileController extends Controller
{
    /**
     * My profile: personal and job details (read-only), contact details (editable), and my records per section with
     * their approval status. Records I add wait for HR approval.
     */
    public function show(Request $request): JsonResponse
    {
        $employee = $this->employee($request)->load(['branch', 'department', 'section', 'jobPosition', 'activeContract.natureOfContract']);
        $sections = [];
        foreach (ProfileSections::SECTIONS as $key => $config) {
            $sections[$key] = $employee->{$config['relation']}()->latest('id')->get()
                ->map(fn ($item) => $this->item($key, $item, $request))->all();
        }

        return response()->json(['data' => [
            'personal' => [
                'employee_no' => $employee->employee_no, 'title' => $employee->emp_title, 'fname' => $employee->fname, 'mname' => $employee->mname, 'lname' => $employee->lname,
                'gender' => $employee->gender, 'date_of_birth' => $employee->date_of_birth?->toDateString(), 'place_of_birth' => $employee->place_of_birth,
                'nationality' => $employee->nationality, 'marital_status' => O::MARITAL_STATUSES[$employee->marital_status] ?? $employee->marital_status,
                'id_type' => O::ID_TYPES[$employee->id_type] ?? $employee->id_type, 'id_number' => $employee->id_number, 'tin' => $employee->tin,
                'nssf_no' => $employee->nssf_no, 'nhif_no' => $employee->nhif_no,
            ],
            'job' => [
                'branch' => $employee->branch?->name, 'department' => $employee->department?->name, 'section' => $employee->section?->name,
                'position' => $employee->jobPosition?->title, 'emp_type' => O::EMPLOYMENT_TYPES[$employee->emp_type] ?? $employee->emp_type,
                'employment_date' => $employee->employment_date?->toDateString(),
                'employment_status' => O::EMPLOYMENT_STATUSES[$employee->employment_status] ?? $employee->employment_status,
                'contract_type' => $employee->activeContract?->natureOfContract?->contract_name, 'contract_end' => $employee->activeContract?->end_date?->toDateString(),
            ],
            'contact' => ['phone_number' => $employee->phone_number, 'email' => $employee->email, 'home_address' => $employee->home_address],
            'sections' => $sections,
            'options' => [
                'education_levels' => O::all()['education_levels'], 'document_types' => O::all()['document_types'], 'relationships' => O::all()['relationships'],
                'genders' => O::all()['genders'],
                'licence_types' => ProfessionalLicence::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->name])->all(),
            ],
        ]]);
    }

    /**
     * Update my phone number and home address. My email is changed under My account (it needs a code).
     */
    public function updateContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:30', 'regex:/^[0-9\s\-\+\(\)]+$/'],
            'home_address' => ['nullable', 'string', 'max:255'],
        ]);
        $employee = $this->employee($request);
        $employee->update($data + ['updated_by' => $request->user()->username]);

        return response()->json(['data' => ['phone_number' => $employee->phone_number, 'email' => $employee->email, 'home_address' => $employee->home_address]]);
    }

    /**
     * Add a record to a section (multipart when it has a file). It waits for HR approval.
     */
    public function store(Request $request, string $section, ManageProfileItems $items): JsonResponse
    {
        $employee = $this->employee($request);
        $config = ProfileSections::SECTIONS[$section];
        $data = Validator::make($request->all(), ProfileSections::rules($section, $employee))->validate();
        $item = $items->submit($employee, $section, $data, $config['file'] ? $request->file($config['file']['input']) : null, $request->user());

        return response()->json(['data' => $this->item($section, $item, $request)], 201);
    }

    /**
     * Withdraw a record waiting for approval, or remove one HR did not approve.
     */
    public function destroy(Request $request, string $section, int $id, ManageProfileItems $items): Response
    {
        $items->withdraw($this->find($request, $section, $id), $section, $request->user());

        return response()->noContent();
    }

    /**
     * Download the file of one of my records.
     */
    public function file(Request $request, string $section, int $id): StreamedResponse
    {
        $file = ProfileSections::SECTIONS[$section]['file'];
        abort_unless($file, 404);
        $item = $this->find($request, $section, $id);

        return EmployeeFiles::response($item->{$file['path']}, $item->{$file['name']});
    }

    private function employee(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return $employee;
    }

    private function find(Request $request, string $section, int $id)
    {
        return $this->employee($request)->{ProfileSections::SECTIONS[$section]['relation']}()->findOrFail($id);
    }

    private function item(string $section, $item, Request $request): array
    {
        $config = ProfileSections::SECTIONS[$section];
        $data = (new $config['resource']($item))->toArray($request);
        if ($config['file'] && $item->{$config['file']['path']}) {
            $url = route('self.profile.file', [$section, $item->id], false);
            foreach ($data as $key => $value) {
                if (is_array($value) && isset($value['url'])) {
                    $data[$key]['url'] = $url;
                }
            }
            if (isset($data['url'])) {
                $data['url'] = $url;
            }
        }

        return $data;
    }
}
