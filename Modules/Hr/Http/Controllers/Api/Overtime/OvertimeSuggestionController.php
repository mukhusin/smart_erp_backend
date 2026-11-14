<?php

namespace Modules\Hr\Http\Controllers\Api\Overtime;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\Employee;
use App\Models\Overtime;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Actions\ManageOvertime;
use Modules\Hr\Support\EmployeeScope;

/**
 * Overtime found in attendance (punch out after the shift, or work on a day off) that nobody has claimed yet.
 * HR turns suggestions into claims, which go through the normal overtime approval.
 *
 * @tags Overtime: Suggestions from attendance
 */
class OvertimeSuggestionController extends Controller
{
    public function __construct(private readonly ManageOvertime $overtime, private readonly EmployeeScope $scope) {}

    /**
     * Unclaimed overtime from attendance in a month (default this month). Empty when attendance is switched off.
     *
     * @response array{data: list<array{employee: array{id: int, name: string, employee_no: string|null, department: string|null}, date: string, planned: string|null, punch_in: string|null, punch_out: string|null, start: string, end: string, overtime_minutes: int, day_off: bool}>, meta: array{attendance: bool}}
     */
    #[QueryParameter('month', type: 'string', example: '2026-11')]
    public function index(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        if (! AttendanceSetting::current()->enabled()) {
            return response()->json(['data' => [], 'meta' => ['attendance' => false]]);
        }
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();

        return response()->json(['data' => $this->suggestions($request, $from, $from->copy()->endOfMonth())->values(), 'meta' => ['attendance' => true]]);
    }

    /**
     * Turn suggestions into overtime claims (source `attendance`). Each claim goes through the overtime workflow.
     * Returns how many were created and why the others were not.
     *
     * @response array{data: array{created: int, failed: list<array{employee_id: int, date: string, message: string}>}}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'], 'items.*.employee_id' => ['required', 'integer'], 'items.*.date' => ['required', 'date'],
            'task_title' => ['nullable', 'string', 'max:150'],
        ]);
        abort_unless(AttendanceSetting::current()->enabled(), 422, 'Attendance is switched off.');
        $created = 0;
        $failed = [];
        foreach ($d['items'] as $item) {
            $date = Carbon::parse($item['date'])->startOfDay();
            $s = $this->suggestions($request, $date, $date, (int) $item['employee_id'])->first();
            if (! $s) {
                $failed[] = ['employee_id' => (int) $item['employee_id'], 'date' => $date->toDateString(), 'message' => 'No unclaimed overtime on this day.'];

                continue;
            }
            try {
                $this->overtime->claim(Employee::findOrFail($item['employee_id']), [
                    'date' => substr($s['start'], 0, 10), 'start_time' => substr($s['start'], 11, 5), 'end_time' => substr($s['end'], 11, 5),
                    'task_title' => $d['task_title'] ?? ($s['day_off'] ? 'Worked on a day off' : 'Stayed after the shift'),
                    'description' => 'From attendance: punched '.substr((string) $s['punch_in'], 11, 5).' – '.substr((string) $s['punch_out'], 11, 5).'.',
                ], $request->user(), 'attendance');
                $created++;
            } catch (ValidationException $e) {
                $failed[] = ['employee_id' => (int) $item['employee_id'], 'date' => $s['date'], 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['data' => ['created' => $created, 'failed' => $failed]]);
    }

    private function suggestions(Request $request, Carbon $from, Carbon $to, ?int $employeeId = null)
    {
        $rows = AttendanceTransa::with('employee.department')
            ->whereHas('employee', fn ($q) => $this->scope->apply($q, $request->user()))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $to)
            ->where('has_overtime', true)->where('overtime_minutes', '>=', ManageOvertime::MIN_MINUTES)
            ->whereNotNull('punch_in_time')->whereNotNull('punch_out_time')->orderBy('transaction_date')->get();

        return $rows->map(function (AttendanceTransa $r) {
            $dayOff = ! $r->attendance_out_time;
            $start = Carbon::parse($dayOff ? $r->punch_in_time : $r->attendance_out_time);
            $end = Carbon::parse($r->punch_out_time);
            if ($end->lte($start)) {
                return null;
            }
            $claimed = Overtime::where('employee_id', $r->employee_id)->whereIn('status', ['pending', 'approved'])
                ->where('from_datetime', '<', $end)->where('to_datetime', '>', $start)->exists();
            if ($claimed) {
                return null;
            }

            return [
                'employee' => ['id' => $r->employee_id, 'name' => $r->employee?->full_name, 'employee_no' => $r->employee?->employee_no, 'department' => $r->employee?->department?->name],
                'date' => Carbon::parse($r->transaction_date)->toDateString(), 'planned' => $r->timetable_detail,
                'punch_in' => Carbon::parse($r->punch_in_time)->format('Y-m-d H:i'), 'punch_out' => $end->format('Y-m-d H:i'),
                'start' => $start->format('Y-m-d H:i'), 'end' => $end->format('Y-m-d H:i'),
                'overtime_minutes' => (int) $start->diffInMinutes($end), 'day_off' => $dayOff,
            ];
        })->filter();
    }
}
