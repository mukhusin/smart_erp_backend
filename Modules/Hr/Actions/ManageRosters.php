<?php

namespace Modules\Hr\Actions;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTimetable;
use App\Models\Roster;
use App\Models\RosterCode;
use App\Models\User;
use App\Services\WorkCalendar;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Workflow\DutyRosterProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Duty rosters (HRMS Department → Roster): a department or section plans each employee's duty for a period.
 * Submitting publishes it, through the "Duty roster" approval workflow when the company set one up.
 * Only published rosters count on the working calendar. Past days of a published roster are locked.
 */
class ManageRosters
{
    public const MAX_DAYS = 62;

    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function create(array $data, User $by): Roster
    {
        [$department, $section] = $this->place($data['department_id'], $data['section_id'] ?? null);
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $this->checkPeriod($department->id, $section?->id, $from, $to);
        $source = ! empty($data['repeat_from_id']) ? Roster::duty()->findOrFail($data['repeat_from_id']) : null;

        return DB::transaction(function () use ($data, $by, $department, $section, $from, $to, $source) {
            $roster = Roster::create([
                'department_id' => $department->id, 'section_id' => $section?->id, 'type' => 'duty', 'status' => Roster::DRAFT, 'is_active' => false,
                'title' => trim($data['title'] ?? '') ?: ($section?->name ?? $department->name).' · '.$this->periodLabel($from, $to),
                'from_date' => $from, 'to_date' => $to, 'roster_year' => (string) $from->year,
                'prepared_by' => $by->employee?->id, 'created_by' => $by->id,
            ]);
            if ($source) {
                $this->repeat($source, $roster, $by);
            }

            return $roster;
        });
    }

    public function update(Roster $roster, array $data): Roster
    {
        $this->mustBeDraft($roster);
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $this->checkPeriod($roster->department_id, $roster->section_id, $from, $to, $roster->id);

        DB::transaction(function () use ($roster, $data, $from, $to) {
            $roster->update(['title' => trim($data['title']), 'from_date' => $from, 'to_date' => $to, 'roster_year' => (string) $from->year]);
            $roster->duties()->where(fn ($q) => $q->whereDate('day_date', '<', $from->toDateString())->orWhereDate('day_date', '>', $to->toDateString()))->delete();
        });

        return $roster->refresh();
    }

    public function delete(Roster $roster): void
    {
        $this->mustBeDraft($roster);
        DB::transaction(function () use ($roster) {
            $roster->duties()->delete();
            $roster->delete();
        });
    }

    /** @return Collection<int, Employee> */
    public function employees(Roster $roster): Collection
    {
        $inRoster = $roster->duties()->distinct()->pluck('employee_id');

        return Employee::with('jobPosition')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('department_id', $roster->department_id)->where('employment_status', '!=', 'terminated')
                    ->when($roster->section_id, fn ($q) => $q->where('section_id', $roster->section_id)))
                ->orWhereIn('id', $inRoster))
            ->orderBy('fname')->orderBy('lname')->get();
    }

    /**
     * Sets, changes or clears duties. Each item: employee_id, date, roster_code_id (null clears), overtime.
     *
     * @param  list<array{employee_id: int, date: string, roster_code_id: int|null, overtime?: bool}>  $items
     */
    public function saveDuties(Roster $roster, array $items, User $by): int
    {
        if (! $roster->editable()) {
            throw ValidationException::withMessages(['duties' => 'This roster is waiting for approval. Withdraw it to make changes.']);
        }
        $employees = $this->employees($roster)->pluck('id')->flip();
        $codes = RosterCode::whereIn('id', collect($items)->pluck('roster_code_id')->filter()->unique())->get()->keyBy('id');
        $today = now()->startOfDay();
        $errors = [];

        foreach ($items as $i => $item) {
            $date = Carbon::parse($item['date'])->startOfDay();
            if (! $employees->has($item['employee_id'])) {
                $errors["duties.$i.employee_id"] = 'This employee is not on this roster.';
            } elseif ($date->lt($roster->from_date) || $date->gt($roster->to_date)) {
                $errors["duties.$i.date"] = 'This day is outside the roster period.';
            } elseif ($roster->status === Roster::PUBLISHED && $date->lt($today)) {
                $errors["duties.$i.date"] = 'Past days of a published roster cannot change.';
            } elseif ($item['roster_code_id'] && ! $codes->has($item['roster_code_id'])) {
                $errors["duties.$i.roster_code_id"] = 'Choose a duty code.';
            }
        }
        $clash = EmployeeTimetable::with(['roster:id,title', 'employee:id,fname,lname'])->where('source', EmployeeTimetable::ROSTER)->where('roster_id', '!=', $roster->id)
            ->where(function ($q) use ($items) {
                foreach ($items as $item) {
                    if ($item['roster_code_id']) {
                        $q->orWhere(fn ($q) => $q->where('employee_id', $item['employee_id'])->whereDate('day_date', $item['date']));
                    }
                }
            })->first();
        if ($clash) {
            $errors['duties'] = "{$clash->employee?->full_name} is already on {$clash->roster?->title} on ".$clash->day_date->format('d M Y').'.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($roster, $items, $codes, $by) {
            foreach ($items as $item) {
                $existing = $roster->duties()->where('employee_id', $item['employee_id'])->whereDate('day_date', $item['date'])->first();
                if (! $item['roster_code_id']) {
                    $existing?->delete();

                    continue;
                }
                $duty = $existing ?? new EmployeeTimetable(['employee_id' => $item['employee_id'], 'day_date' => $item['date'], 'source' => EmployeeTimetable::ROSTER, 'roster_id' => $roster->id]);
                $duty->fillFromCode($codes[$item['roster_code_id']])->fill(['overtime' => (bool) ($item['overtime'] ?? false), 'updated_by' => $by->id])->save();
            }
            app(WorkCalendar::class)->forget();

            return count($items);
        });
    }

    public function submit(Roster $roster, User $by): Roster
    {
        if (! in_array($roster->status, [Roster::DRAFT, Roster::REJECTED], true)) {
            throw ValidationException::withMessages(['roster' => 'This roster is already '.strtolower(Roster::STATUS_LABELS[$roster->status] ?? $roster->status).'.']);
        }
        if (! $roster->duties()->exists()) {
            throw ValidationException::withMessages(['roster' => 'Give employees their duties before submitting.']);
        }
        $roster->update(['status' => Roster::PENDING, 'submitted_at' => now(), 'decision_comment' => null]);
        if (! $this->workflow->start(DutyRosterProcess::KEY, $roster, $by)) {
            $this->publish($roster);
        }

        return $roster->refresh();
    }

    public function publish(Roster $roster): void
    {
        $roster->update(['status' => Roster::PUBLISHED, 'published_at' => now(), 'is_active' => true, 'approved_by' => auth()->user()?->employee?->id]);
        app(WorkCalendar::class)->forget();
    }

    public function reject(Roster $roster, ?string $comment): void
    {
        $roster->update(['status' => Roster::REJECTED, 'decision_comment' => $comment]);
    }

    public function withdraw(Roster $roster, User $by): Roster
    {
        if ($roster->status !== Roster::PENDING) {
            throw ValidationException::withMessages(['roster' => 'Only a roster waiting for approval can be withdrawn.']);
        }
        if ($instance = $roster->workflow()->where('status', 'pending')->first()) {
            $this->workflow->cancel($instance, $by);
        }
        $roster->update(['status' => Roster::DRAFT]);

        return $roster->refresh();
    }

    /** Fills a new roster by repeating the duties of another, day by day in order (rotations carry on). */
    public function repeat(Roster $source, Roster $target, User $by): void
    {
        $length = (int) $source->from_date->diffInDays($source->to_date) + 1;
        $employees = $this->employees($target)->pluck('id')->flip();
        $taken = EmployeeTimetable::where('source', EmployeeTimetable::ROSTER)->where('roster_id', '!=', $target->id)
            ->whereDate('day_date', '>=', $target->from_date->toDateString())->whereDate('day_date', '<=', $target->to_date->toDateString())
            ->get(['employee_id', 'day_date'])->map(fn ($d) => $d->employee_id.'|'.$d->day_date->toDateString())->flip();
        $duties = $source->duties()->with('rosterCode')->get()->groupBy('employee_id');
        $offset = (int) $source->from_date->diffInDays($target->from_date) % $length;

        foreach ($duties as $employeeId => $rows) {
            if (! $employees->has($employeeId)) {
                continue;
            }
            $byDay = $rows->keyBy(fn ($d) => (int) $source->from_date->diffInDays($d->day_date));
            foreach (CarbonPeriod::create($target->from_date, $target->to_date) as $i => $day) {
                $from = $byDay->get(($offset + $i) % $length);
                if (! $from || $taken->has($employeeId.'|'.$day->toDateString())) {
                    continue;
                }
                $duty = new EmployeeTimetable(['employee_id' => $employeeId, 'day_date' => $day->toDateString(), 'source' => EmployeeTimetable::ROSTER, 'roster_id' => $target->id]);
                $from->rosterCode && $from->rosterCode->is_active
                    ? $duty->fillFromCode($from->rosterCode)
                    : $duty->fill($from->only(['roster_code_id', 'code', 'duty', 'can_punch', 'pass_day', 'start_time', 'end_time', 'break_minutes', 'work_hour', 'color']));
                $duty->fill(['overtime' => $from->overtime, 'updated_by' => $by->id])->save();
            }
        }
    }

    public function periodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameMonth($to) && $from->day === 1 && $to->isLastOfMonth()) {
            return $from->format('F Y');
        }

        return $from->format('d M').' – '.$to->format($from->isSameYear($to) ? 'd M Y' : 'd M Y');
    }

    private function mustBeDraft(Roster $roster): void
    {
        if (! in_array($roster->status, [Roster::DRAFT, Roster::REJECTED], true)) {
            throw ValidationException::withMessages(['roster' => 'Only a draft or returned roster can be changed or deleted.']);
        }
    }

    /** @return array{0: Department, 1: Department|null} */
    private function place(int $departmentId, ?int $sectionId): array
    {
        $department = Department::findOrFail($departmentId);
        if ($department->parent_id) {
            return [Department::findOrFail($department->parent_id), $department];
        }
        $section = $sectionId ? Department::where('parent_id', $department->id)->find($sectionId) : null;
        if ($sectionId && ! $section) {
            throw ValidationException::withMessages(['section_id' => 'This section is not in the department.']);
        }

        return [$department, $section];
    }

    private function checkPeriod(int $departmentId, ?int $sectionId, Carbon $from, Carbon $to, ?int $except = null): void
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to_date' => 'The end must be on or after the start.']);
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to_date' => 'A roster covers at most '.self::MAX_DAYS.' days.']);
        }
        $overlap = Roster::duty()->where('department_id', $departmentId)
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId), fn ($q) => $q->whereNull('section_id'))
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->first();
        if ($overlap) {
            throw ValidationException::withMessages(['from_date' => "{$overlap->title} already covers part of this period."]);
        }
    }
}
