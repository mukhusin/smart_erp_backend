<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmpSupervisor;
use App\Services\ReportingLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Supervisors (HRMS emp_supervisor): one active supervisor per employee. Assigning a new one closes the current one
 * (history kept). Approval workflows can send a step to the employee's supervisor.
 *
 * @tags HR: Supervisors
 */
class SupervisorController extends Controller
{
    /**
     * Who the employee reports to, their supervisor history and the people who report to them.
     *
     * `current.source`: `assigned` (set on the profile) or `department_head` (default: the head of their department).
     * `current.title`: what to show: the assigned person's name, or the role ("Head of Nursing") for the default.
     * The team includes assigned reports and, for a department head, the department's staff without an assigned supervisor.
     *
     * @response array{data: array{current: array{id: int, name: string, employee_no: string, position: string|null, photo_url: string|null, source: string, title: string, since: string|null}|null, history: list<array{id: int, supervisor: array{id: int, name: string, employee_no: string, position: string|null, photo_url: string|null}, from_date: string, to_date: string|null, active: bool, reason: string|null, assigned_by: string|null}>, team: list<array{id: int, name: string, employee_no: string, position: string|null, photo_url: string|null, since: string|null, source: string}>}}
     */
    public function index(Employee $employee, ReportingLine $line): JsonResponse
    {
        $history = $employee->supervisions()->with('supervisor.jobPosition', 'assignedBy.employee')->get()->sortByDesc(fn ($s) => $s->status === 'active')->values();
        $current = $line->supervisorOf($employee);

        return response()->json(['data' => [
            'current' => $current ? $this->person($current['employee']) + ['source' => $current['source'], 'title' => $current['title'], 'since' => $current['since']?->toDateString()] : null,
            'history' => $history->map(fn (EmpSupervisor $s) => [
                'id' => $s->id,
                'supervisor' => $this->person($s->supervisor),
                'from_date' => $s->from_date->toDateString(),
                'to_date' => $s->to_date?->toDateString(),
                'active' => $s->status === 'active',
                'reason' => $s->reason,
                'assigned_by' => $s->assignedBy?->display_name,
            ])->all(),
            'team' => $line->teamOf($employee)->map(fn ($r) => $this->person($r['employee']) + ['since' => $r['since']?->toDateString(), 'source' => $r['source']])->all(),
        ]]);
    }

    /**
     * Assign a supervisor (replaces the current one).
     *
     * @response array{message: string}
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'supervisor_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $employee->company_id)->whereNull('deleted_at')],
            'from_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['supervisor_id.required' => 'Choose the supervisor.']);

        $supervisor = Employee::findOrFail($data['supervisor_id']);
        $this->assertCanSupervise($employee, $supervisor);
        $from = CarbonImmutable::parse($data['from_date']);

        DB::transaction(function () use ($employee, $supervisor, $from, $data, $request) {
            $current = $employee->currentSupervisor()->first();
            if ($current && $from->lt($current->from_date)) {
                throw ValidationException::withMessages(['from_date' => 'The current supervisor started on '.$current->from_date->format('d M Y').'. Choose a later date.']);
            }
            $current?->update(['status' => 'inactive', 'to_date' => $from->subDay()->max($current->from_date)]);
            $employee->supervisions()->create([
                'supervisor_id' => $supervisor->id, 'from_date' => $from, 'status' => 'active',
                'reason' => $data['reason'] ?? null, 'assigned_by' => $request->user()->id,
            ]);
        });

        return response()->json(['message' => "{$supervisor->full_name} now supervises {$employee->full_name}."], 201);
    }

    /**
     * End the current supervision without a replacement.
     *
     * @response array{message: string}
     */
    public function end(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:255']]);
        $current = $employee->currentSupervisor()->first();
        abort_unless($current, 422, 'This employee has no supervisor.');
        $current->update(['status' => 'inactive', 'to_date' => CarbonImmutable::parse($data['date'])->max($current->from_date), 'reason' => $data['reason'] ?? $current->reason]);

        return response()->json(['message' => 'Supervision ended.']);
    }

    /**
     * Delete a supervision recorded by mistake.
     */
    public function destroy(Employee $employee, EmpSupervisor $supervision): Response
    {
        $supervision->delete();

        return response()->noContent();
    }

    private function assertCanSupervise(Employee $employee, Employee $supervisor): void
    {
        $message = match (true) {
            $supervisor->id === $employee->id => 'An employee cannot supervise themselves.',
            $supervisor->employment_status === 'terminated' => 'This person has left the organization.',
            (int) $employee->currentSupervisor()->value('supervisor_id') === $supervisor->id => 'This person already supervises the employee.',
            app(ReportingLine::class)->isAbove($employee->id, $supervisor->id) => "{$employee->full_name} supervises {$supervisor->full_name} (directly or through others), so it cannot be the other way round.",
            default => null,
        };
        if ($message) {
            throw ValidationException::withMessages(['supervisor_id' => $message]);
        }
    }

    private function person(?Employee $e): array
    {
        return [
            'id' => (int) $e?->id,
            'name' => $e?->full_name ?? '—',
            'employee_no' => $e?->employee_no ?? '',
            'position' => $e?->jobPosition?->title,
            'photo_url' => $e?->profile_image ? route('hr.employees.photo', $e->id, false) : null,
        ];
    }
}
