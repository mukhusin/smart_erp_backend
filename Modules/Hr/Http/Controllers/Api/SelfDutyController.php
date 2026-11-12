<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The logged-in employee's planned days: roster duties, leave, public holidays and working hours.
 *
 * @tags Self service: My duty roster
 */
class SelfDutyController extends Controller
{
    /**
     * Planned days of a month (default this month).
     *
     * @response array{data: list<array{date: string, kind: string, source: string, code: string|null, label: string, start: string|null, end: string|null, crosses_midnight: bool, hours: float, holiday: string|null, color: string|null, overtime: bool}>, meta: array{month: string, working_days: int, hours: float}}
     */
    public function index(Request $request, WorkCalendar $calendar): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');
        $start = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $days = $calendar->days($employee, $start, $start->copy()->endOfMonth());

        return response()->json([
            'data' => $days->map->toArray()->values(),
            'meta' => ['month' => $month, 'working_days' => $days->filter->isWorking()->count(), 'hours' => round($days->sum('hours'), 2)],
        ]);
    }
}
