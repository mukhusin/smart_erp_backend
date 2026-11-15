<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Attendance\BiotimePersonnel;
use Modules\Hr\Attendance\PunchSources;

/**
 * Sends staff to the fingerprint devices (BioTime) when the company uses them: new staff and changes are pushed one by
 * one, and a nightly job makes sure every current employee with attendance is registered. An employee without an
 * attendance code gets one that is free both here and on the device.
 */
class ManageDevicePersonnel
{
    public function __construct(private readonly PunchSources $sources) {}

    public function enabled(): bool
    {
        return $this->sources->personnelForCurrentCompany() !== null;
    }

    /** @return 'added'|'updated'|null null: the company does not use fingerprint devices, or the employee has no attendance */
    public function push(Employee $employee, ?BiotimePersonnel $device = null): ?string
    {
        $device ??= $this->sources->personnelForCurrentCompany();
        if (! $device || ! $employee->has_attendance || $employee->employment_status === 'terminated') {
            return null;
        }

        return $device->save($employee, $this->codeFor($employee, $device));
    }

    /** @return array{added: int, updated: int, failed: int} */
    public function pushAll(): array
    {
        $device = $this->sources->personnelForCurrentCompany();
        if (! $device) {
            throw ValidationException::withMessages(['attendance' => 'Attendance is not set to use the BioTime fingerprint devices.']);
        }
        $counts = ['added' => 0, 'updated' => 0, 'failed' => 0];
        foreach (Employee::query()->onDuty()->where('has_attendance', true)->orderBy('id')->get() as $employee) {
            try {
                $result = $this->push($employee, $device);
                $result && $counts[$result]++;
            } catch (\Throwable $e) {
                $counts['failed']++;
                Log::warning('Push to fingerprint device failed', ['employee' => $employee->id, 'error' => $e->getMessage()]);
            }
        }

        return $counts;
    }

    private function codeFor(Employee $employee, BiotimePersonnel $device): string
    {
        if (filled($employee->attendance_code)) {
            return (string) $employee->attendance_code;
        }
        $taken = Employee::withoutGlobalScopes()->where('company_id', $employee->company_id)->whereKeyNot($employee->id)
            ->whereNotNull('attendance_code')->pluck('attendance_code')->map(fn ($c) => (string) $c)->all();
        $candidate = (string) $employee->id;
        if (in_array($candidate, $taken, true) || $device->has($candidate)) {
            $numbers = array_filter(array_merge($taken, $device->codes()), fn ($c) => ctype_digit($c));
            $candidate = (string) (max(array_map('intval', $numbers ?: ['0'])) + 1);
        }
        DB::table('employees')->where('id', $employee->id)->update(['attendance_code' => $candidate]);
        $employee->attendance_code = $candidate;

        return $candidate;
    }
}
