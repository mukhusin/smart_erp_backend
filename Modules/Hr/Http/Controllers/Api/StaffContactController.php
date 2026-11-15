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

    /**
     * Download the contacts CSV: current staff with their phone number (and patient ID when used), to correct in Excel and upload.
     */
    public function template(Request $request): \Illuminate\Http\Response
    {
        $patients = (bool) \App\Models\HrSetting::current()->hospital_patients;
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values(array_filter(['employee_no', 'name', 'phone_number', $patients ? 'patient_id' : null])));
        foreach ($this->scope->apply(Employee::query()->onDuty(), $request->user())->with('patientId')->orderBy('employee_no')->get() as $e) {
            fputcsv($out, array_merge([$e->employee_no, $e->full_name, $e->phone_number ?? ''], $patients ? [$e->patientId?->patient_id ?? ''] : []));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="staff-contacts.csv"']);
    }

    /**
     * Check a contacts CSV (employee_no, phone_number, optional patient_id) and, with `commit`, save the changes.
     * Nothing is saved while any row has an error. Empty cells leave the value as it is.
     *
     * @response array{rows: list<array{line: int, employee_no: string|null, name: string|null, changes: list<string>, errors: list<string>}>, changed: int, unchanged: int, invalid: int, saved: int}
     */
    public function import(Request $request): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'commit' => ['boolean']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        if (! in_array('employee_no', $header, true) || ! array_intersect(['phone_number', 'patient_id'], $header)) {
            throw ValidationException::withMessages(['file' => 'The first row must have employee_no and phone_number (and optionally patient_id), as in the downloaded file.']);
        }
        $staff = $this->scope->apply(Employee::query()->onDuty(), $request->user())->with('patientId')->get()->keyBy(fn ($e) => strtoupper(trim((string) $e->employee_no)));
        $patients = (bool) \App\Models\HrSetting::current()->hospital_patients;
        $rows = $updates = $seen = [];
        $pidsInFile = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            $v = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            if (! array_filter($v, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $no = strtoupper(trim((string) $v['employee_no']));
            $e = $staff->get($no);
            $row = ['line' => $line, 'employee_no' => $v['employee_no'] ?: null, 'name' => $e?->full_name, 'changes' => [], 'errors' => []];
            if (! $e) {
                $row['errors'][] = "No current employee with number \"{$v['employee_no']}\" that you can edit.";
            } elseif (isset($seen[$e->id])) {
                $row['errors'][] = "Already on line {$seen[$e->id]}.";
            } else {
                $seen[$e->id] = $line;
                $update = [];
                $phone = preg_replace('/[\s\-+]/', '', (string) ($v['phone_number'] ?? ''));
                if ($phone !== '' && $phone !== (string) $e->phone_number) {
                    preg_match(self::PHONE, $phone) ? $update['phone_number'] = $phone : $row['errors'][] = "Phone \"{$v['phone_number']}\": use 07, 06, 2557 or 2556 followed by 8 digits.";
                    isset($update['phone_number']) && $row['changes'][] = 'Phone '.($e->phone_number ?: '—').' → '.$phone;
                }
                $pid = trim((string) ($v['patient_id'] ?? ''));
                if ($patients && $pid !== '' && $pid !== (string) $e->patientId?->patient_id) {
                    $owner = EmployeePatientId::with('employee')->where('patient_id', $pid)->where('employee_id', '!=', $e->id)->first();
                    if ($owner) {
                        $row['errors'][] = "Patient ID {$pid} belongs to {$owner->employee?->full_name}.";
                    } elseif (isset($pidsInFile[$pid])) {
                        $row['errors'][] = "Patient ID {$pid} is also on line {$pidsInFile[$pid]}.";
                    } else {
                        $pidsInFile[$pid] = $line;
                        $update['patient_id'] = $pid;
                        $row['changes'][] = 'Patient ID '.($e->patientId?->patient_id ?: '—').' → '.$pid;
                    }
                }
                if ($update && ! $row['errors']) {
                    $updates[$e->id] = [$e, $update];
                }
            }
            $rows[] = $row;
        }
        fclose($handle);
        $invalid = collect($rows)->filter(fn ($r) => $r['errors'])->count();
        $result = ['rows' => $rows, 'changed' => count($updates), 'unchanged' => count($rows) - count($updates) - $invalid, 'invalid' => $invalid, 'saved' => 0];
        if (! empty($d['commit'])) {
            if ($invalid || ! $updates) {
                return response()->json($result, 422);
            }
            \Illuminate\Support\Facades\DB::transaction(function () use ($updates) {
                foreach ($updates as [$e, $u]) {
                    if (isset($u['phone_number'])) {
                        $e->update(['phone_number' => $u['phone_number']]);
                    }
                    if (isset($u['patient_id'])) {
                        EmployeePatientId::updateOrCreate(['employee_id' => $e->id], ['patient_id' => $u['patient_id']]);
                    }
                }
            });
            $result['saved'] = count($updates);
        }

        return response()->json($result);
    }

    public static function valid(?string $phone): bool
    {
        return $phone !== null && preg_match(self::PHONE, preg_replace('/[\s\-+]/', '', $phone)) === 1;
    }
}
