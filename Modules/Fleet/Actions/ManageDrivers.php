<?php

namespace Modules\Fleet\Actions;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use App\Models\EmployeeLicence;
use App\Models\ProfessionalLicence;
use Modules\Hr\Support\EmployeeFiles;

/** Drivers are always employees registered in HR; their name, department and leave come from HR. */
class ManageDrivers
{
    public function save(?Driver $driver, array $data, User $by): Driver
    {
        if (! $driver) {
            $employee = Employee::find($data['employee_id']);
            if (! $employee || $employee->employment_status === 'terminated') {
                throw ValidationException::withMessages(['employee_id' => 'Choose a current employee registered in HR.']);
            }
            if (Driver::where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} is already a driver."]);
            }
        }

        return DB::transaction(function () use ($driver, $data, $by) {
            $driver ??= new Driver(['employee_id' => $data['employee_id'], 'created_by' => $by->id]);
            $driver->fill(['status' => $data['status'] ?? $driver->status ?? 'active', 'notes' => $data['notes'] ?? null])->save();
            if ($driver->status !== 'active') {
                $driver->assignments()->whereNull('ended_on')->update(['ended_on' => today()->toDateString()]);
            }

            return $driver->refresh();
        });
    }

    /** Saves the licence on the employee's HR profile, where HR also sees and renews it. */
    public function addLicence(Driver $driver, array $data, ?UploadedFile $file, User $by): EmployeeLicence
    {
        $classes = collect($data['classes'])->map(fn ($c) => strtoupper(trim((string) $c)))->filter()->unique()->values()->all();
        if (! $classes) {
            throw ValidationException::withMessages(['classes' => 'Enter at least one licence class.']);
        }
        if (Carbon::parse($data['issued_on'])->gte(Carbon::parse($data['expires_on']))) {
            throw ValidationException::withMessages(['expires_on' => 'The expiry date must be after the issue date.']);
        }
        $type = ProfessionalLicence::where('is_driving', true)->where('is_active', true)->orderBy('id')->first()
            ?? ProfessionalLicence::create(['name' => 'Driving Licence', 'is_driving' => true, 'is_active' => true]);
        $employee = $driver->employee;

        return DB::transaction(function () use ($employee, $data, $file, $classes, $type) {
            $l = $employee->licences()->create(['professional_licence_id' => $type->id, 'licence_no' => trim($data['number']), 'classes' => $classes,
                'start_date' => $data['issued_on'], 'end_date' => $data['expires_on']]);
            if ($file) {
                $l->update(['file_path' => EmployeeFiles::store($file, $employee, 'licences'), 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200)]);
            }

            return $l;
        });
    }

    /** Why the driver may not drive this unit, or null when eligible. */
    public function ineligible(Driver $driver, FleetUnit $unit): ?string
    {
        $driver->loadMissing(['employee', 'licences']);
        if ($driver->status !== 'active') {
            return "{$driver->name()} is not an active driver.";
        }
        if ($driver->employee?->employment_status === 'terminated') {
            return "{$driver->name()} has left the organisation.";
        }
        if ($unit->kind === 'generator') {
            return null;
        }
        $licence = $driver->currentLicence();
        if (! $licence || ! $licence->valid()) {
            return "{$driver->name()} has no valid driving licence on the HR profile".($licence ? ' (expired '.$licence->end_date->format('d M Y').').' : '.');
        }
        $needed = $unit->type?->licence_classes ?? [];
        $has = $licence->classes ?? [];
        if ($needed && ! $has) {
            return "The classes of {$driver->name()}'s driving licence are not entered.";
        }
        if ($needed && ! array_intersect($needed, $has)) {
            return "{$driver->name()}'s licence (".implode(', ', $has).") does not cover {$unit->type->name}: needs ".implode(' or ', $needed).'.';
        }

        return null;
    }

    public function assign(FleetUnit $unit, Driver $driver, array $data, User $by): Assignment
    {
        if (in_array($unit->status, ['disposed', 'lent'], true)) {
            throw ValidationException::withMessages(['fleet_driver_id' => 'This unit is '.strtolower(FleetUnit::STATUSES[$unit->status]).'.']);
        }
        if ($why = $this->ineligible($driver, $unit)) {
            throw ValidationException::withMessages(['fleet_driver_id' => $why]);
        }
        if (! empty($data['ends_on']) && Carbon::parse($data['ends_on'])->lt(Carbon::parse($data['starts_on']))) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be after the start date.']);
        }

        return DB::transaction(function () use ($unit, $driver, $data, $by) {
            $unit->assignments()->whereNull('ended_on')->update(['ended_on' => Carbon::parse($data['starts_on'])->toDateString()]);
            $a = $unit->assignments()->create(['fleet_driver_id' => $driver->id, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => $by->id]);
            $driver->employee?->user?->notify(new Notice('Vehicle assigned to you', "You are the driver of {$unit->label()} from ".Carbon::parse($data['starts_on'])->format('d M Y').'.', '/self-service/my-vehicles', 'truck-front'));

            return $a;
        });
    }

    public function endAssignment(Assignment $a, string $date): void
    {
        if ($a->ended_on) {
            throw ValidationException::withMessages(['assignment' => 'This assignment has already ended.']);
        }
        $a->update(['ended_on' => Carbon::parse($date)->max($a->starts_on)->toDateString()]);
    }
}
