<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePatientId;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Support\EmployeeScope;

/**
 * Phone numbers and patient IDs of current staff, kept up to date in one list (SMS and the patient system depend on them).
 *
 * @tags HR: Staff contacts
 */
class StaffContactController extends Controller
{
    /** Tanzanian mobile numbers: 07/06 + 8 digits, or 2557/2556 + 8 digits. */
    public const PHONE = '/^(0[67]\d{8}|255[67]\d{8})$/';

    public function __construct(private readonly EmployeeScope $scope) {}

    /**
     * Current staff with their phone number and patient ID.
     *
     * `filter`: missing_phone, invalid_phone or missing_patient_id.
     *
     * @response array{data: list<array{id: int, employee_no: string|null, name: string, department: string|null, position: string|null, phone_number: string|null, phone_valid: bool, patient_id: string|null}>, meta: array{total: int, missing_phone: int, invalid_phone: int, missing_patient_id: int, patient_ids: bool}}
     */
    #[QueryParameter('filter', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['filter' => ['nullable', 'in:missing_phone,invalid_phone,missing_patient_id']])['filter'] ?? null;
        $rows = $this->scope->apply(Employee::query()->onDuty(), $request->user())->with(['department:id,name', 'jobPosition:id,title', 'patientId'])
            ->orderBy('employee_no')->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->full_name, 'department' => $e->department?->name, 'position' => $e->jobPosition?->title,
                'phone_number' => $e->phone_number, 'phone_valid' => self::valid($e->phone_number), 'patient_id' => $e->patientId?->patient_id,
            ]);
        $meta = [
            'total' => $rows->count(),
            'missing_phone' => $rows->filter(fn ($r) => blank($r['phone_number']))->count(),
            'invalid_phone' => $rows->filter(fn ($r) => filled($r['phone_number']) && ! $r['phone_valid'])->count(),
            'missing_patient_id' => $rows->filter(fn ($r) => blank($r['patient_id']))->count(),
            /** Patient IDs are used only when the company runs a hospital (HR settings). */
            'patient_ids' => (bool) \App\Models\HrSetting::current()->hospital_patients,
        ];
        $rows = match ($filter) {
            'missing_phone' => $rows->filter(fn ($r) => blank($r['phone_number'])),
            'invalid_phone' => $rows->filter(fn ($r) => filled($r['phone_number']) && ! $r['phone_valid']),
            'missing_patient_id' => $rows->filter(fn ($r) => blank($r['patient_id'])),
            default => $rows,
        };

        return response()->json(['data' => $rows->values(), 'meta' => $meta]);
    }

    /**
     * Change an employee's phone number and/or patient ID. Spaces in the phone number are removed. An empty patient ID removes it.
     *
     * @response array{data: array{id: int, employee_no: string|null, name: string, department: string|null, position: string|null, phone_number: string|null, phone_valid: bool, patient_id: string|null}}
     */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $employee), 404);
        $request->merge(['phone_number' => $request->has('phone_number') ? preg_replace('/[\s\-+]/', '', (string) $request->input('phone_number')) : null]);
        $d = $request->validate([
            'phone_number' => ['sometimes', 'nullable', 'string', 'regex:'.self::PHONE],
            'patient_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], ['phone_number.regex' => 'Use a mobile number starting with 07, 06, 2557 or 2556 followed by 8 digits.']);

        if (array_key_exists('phone_number', $d)) {
            $employee->update(['phone_number' => $d['phone_number'] ?: null]);
        }
        if (array_key_exists('patient_id', $d)) {
            $pid = trim((string) $d['patient_id']);
            if ($pid === '') {
                EmployeePatientId::where('employee_id', $employee->id)->delete();
            } else {
                $taken = EmployeePatientId::with('employee')->where('patient_id', $pid)->where('employee_id', '!=', $employee->id)->first();
                if ($taken) {
                    throw ValidationException::withMessages(['patient_id' => "Patient ID {$pid} belongs to {$taken->employee?->full_name} ({$taken->employee?->employee_no})."]);
                }
                EmployeePatientId::updateOrCreate(['employee_id' => $employee->id], ['patient_id' => $pid]);
            }
        }
        $employee->load(['department:id,name', 'jobPosition:id,title', 'patientId']);

        return response()->json(['data' => [
            'id' => $employee->id, 'employee_no' => $employee->employee_no, 'name' => $employee->full_name, 'department' => $employee->department?->name,
            'position' => $employee->jobPosition?->title, 'phone_number' => $employee->phone_number, 'phone_valid' => self::valid($employee->phone_number),
            'patient_id' => $employee->patientId?->patient_id,
        ]]);
    }

    public static function valid(?string $phone): bool
    {
        return $phone !== null && preg_match(self::PHONE, preg_replace('/[\s\-+]/', '', $phone)) === 1;
    }
}
