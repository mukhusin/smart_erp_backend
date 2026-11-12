<?php

namespace Modules\Hr\Http\Controllers\Api\Roster;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\WorkSchedule;
use App\Services\PlannedDay;
use App\Services\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Http\Requests\Roster\WorkScheduleRequest;

/**
 * Working hours: the days and times employees work when they are not on a duty roster.
 * The default applies to everyone; a department or section can use other hours. Without any, Monday to Friday
 * 08:00 to 17:00 is used (as in HRMS). Days not listed are days off.
 *
 * @tags Roster: Working hours
 */
class WorkScheduleController extends Controller
{
    /**
     * List working hours with the departments using each. `meta.departments` lists every active department and
     * section to choose from.
     *
     * @response array{data: list<array{id: int|null, name: string, days: list<array{day: int, start: string, end: string, hours: float}>, break_minutes: int, weekly_hours: float, is_default: bool, built_in: bool, departments: list<array{id: int, name: string}>}>, meta: array{departments: list<array{id: int, name: string, parent: string|null, branch: string|null, work_schedule_id: int|null}>}}
     */
    public function index(): JsonResponse
    {
        $schedules = WorkSchedule::with(['departments' => fn ($q) => $q->orderBy('name')])->orderByDesc('is_default')->orderBy('name')->get();
        $rows = $schedules->map(fn ($s) => self::row($s));
        if ($schedules->isEmpty()) {
            $rows->push(self::row(WorkCalendar::builtIn()));
        }

        $departments = Department::with(['parent:id,name', 'branch:id,name'])->where('is_active', true)->get()
            ->sortBy(fn ($d) => ($d->parent?->name ?? $d->name).' '.($d->parent ? $d->name : ''))->values()
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'parent' => $d->parent?->name, 'branch' => $d->branch?->name, 'work_schedule_id' => $d->work_schedule_id]);

        return response()->json(['data' => $rows, 'meta' => ['departments' => $departments]]);
    }

    /**
     * Add working hours.
     *
     * @response array{data: array{id: int|null, name: string, days: list<array{day: int, start: string, end: string, hours: float}>, break_minutes: int, weekly_hours: float, is_default: bool, built_in: bool, departments: list<array{id: int, name: string}>}}
     */
    public function store(WorkScheduleRequest $request): JsonResponse
    {
        return response()->json(['data' => self::row($this->save($request, new WorkSchedule))], 201);
    }

    /**
     * Update working hours.
     *
     * @response array{data: array{id: int|null, name: string, days: list<array{day: int, start: string, end: string, hours: float}>, break_minutes: int, weekly_hours: float, is_default: bool, built_in: bool, departments: list<array{id: int, name: string}>}}
     */
    public function update(WorkScheduleRequest $request, WorkSchedule $workSchedule): JsonResponse
    {
        return response()->json(['data' => self::row($this->save($request, $workSchedule))]);
    }

    /** Delete working hours. Its departments go back to the default. The default cannot be deleted while others exist. */
    public function destroy(WorkSchedule $workSchedule): Response
    {
        abort_if($workSchedule->is_default && WorkSchedule::whereKeyNot($workSchedule->id)->exists(), 422, 'Make other working hours the default first.');
        $workSchedule->delete();

        return response()->noContent();
    }

    public static function row(WorkSchedule $s): array
    {
        $days = collect($s->days ?? [])->sortBy('day')->values()->map(fn ($d) => $d + [
            'hours' => PlannedDay::working(now(), 'schedule', null, '', $d['start'], $d['end'], (int) $s->break_minutes)->hours,
        ]);

        return [
            'id' => $s->id, 'name' => $s->name, 'days' => $days->all(), 'break_minutes' => (int) $s->break_minutes,
            'weekly_hours' => round($days->sum('hours'), 2), 'is_default' => (bool) $s->is_default, 'built_in' => ! $s->exists,
            'departments' => $s->exists ? $s->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->all() : [],
        ];
    }

    private function save(WorkScheduleRequest $request, WorkSchedule $schedule): WorkSchedule
    {
        $data = $request->validated();

        return DB::transaction(function () use ($schedule, $data) {
            $first = ! WorkSchedule::query()->whereKeyNot($schedule->id ?? 0)->exists();
            $default = $first || ($data['is_default'] ?? $schedule->is_default ?? false);
            if (! $default && $schedule->is_default) {
                throw ValidationException::withMessages(['is_default' => 'Make other working hours the default instead.']);
            }
            $schedule->fill([
                'name' => trim($data['name']), 'break_minutes' => (int) ($data['break_minutes'] ?? 0), 'is_default' => $default,
                'days' => collect($data['days'])->map(fn ($d) => ['day' => (int) $d['day'], 'start' => $d['start'], 'end' => $d['end']])->sortBy('day')->values()->all(),
            ])->save();
            if ($default) {
                WorkSchedule::whereKeyNot($schedule->id)->update(['is_default' => false]);
            }
            if (array_key_exists('department_ids', $data)) {
                Department::where('work_schedule_id', $schedule->id)->whereNotIn('id', $data['department_ids'])->update(['work_schedule_id' => null]);
                Department::whereIn('id', $data['department_ids'])->update(['work_schedule_id' => $schedule->id]);
            }
            app(WorkCalendar::class)->forget();

            return $schedule->load(['departments' => fn ($q) => $q->orderBy('name')]);
        });
    }
}
