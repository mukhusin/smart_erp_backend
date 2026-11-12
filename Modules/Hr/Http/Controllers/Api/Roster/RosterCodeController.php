<?php

namespace Modules\Hr\Http\Controllers\Api\Roster;

use App\Http\Controllers\Controller;
use App\Models\RosterCode;
use App\Services\PlannedDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\Roster\RosterCodeRequest;

/**
 * Duty codes used on duty rosters (HRMS Settings → Duty timetable), e.g. M = Morning 07:30 to 15:30, N = Night, O = Off.
 *
 * @tags Roster: Duty codes
 */
class RosterCodeController extends Controller
{
    /**
     * List duty codes.
     *
     * @response array{data: list<array{id: int, code: string, duty: string, description: string|null, is_working: bool, start_time: string|null, end_time: string|null, crosses_midnight: bool, work_hour: float, break_minutes: int, color: string|null, is_active: bool}>}
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => RosterCode::orderByDesc('is_active')->orderBy('code')->get()->map(fn ($c) => self::row($c))]);
    }

    /**
     * Add a duty code.
     *
     * @response array{data: array{id: int, code: string, duty: string, description: string|null, is_working: bool, start_time: string|null, end_time: string|null, crosses_midnight: bool, work_hour: float, break_minutes: int, color: string|null, is_active: bool}}
     */
    public function store(RosterCodeRequest $request): JsonResponse
    {
        return response()->json(['data' => self::row(RosterCode::create($this->data($request)))], 201);
    }

    /**
     * Update a duty code. Days already on rosters keep the times they were given.
     *
     * @response array{data: array{id: int, code: string, duty: string, description: string|null, is_working: bool, start_time: string|null, end_time: string|null, crosses_midnight: bool, work_hour: float, break_minutes: int, color: string|null, is_active: bool}}
     */
    public function update(RosterCodeRequest $request, RosterCode $rosterCode): JsonResponse
    {
        $rosterCode->update($this->data($request, $rosterCode));

        return response()->json(['data' => self::row($rosterCode)]);
    }

    /** Delete a duty code. */
    public function destroy(RosterCode $rosterCode): Response
    {
        $rosterCode->delete();

        return response()->noContent();
    }

    public static function row(RosterCode $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'duty' => $c->duty, 'description' => $c->description, 'is_working' => (bool) $c->can_punch,
            'start_time' => $c->start_time, 'end_time' => $c->end_time, 'crosses_midnight' => (bool) $c->pass_day,
            'work_hour' => (float) $c->work_hour, 'break_minutes' => (int) $c->break_minutes, 'color' => $c->color, 'is_active' => (bool) ($c->is_active ?? true),
        ];
    }

    private function data(RosterCodeRequest $request, ?RosterCode $code = null): array
    {
        $data = $request->validated();

        $working = (bool) $data['is_working'];
        $day = $working ? PlannedDay::working(now(), 'roster', null, '', $data['start_time'], $data['end_time'], (int) ($data['break_minutes'] ?? 0)) : null;

        return [
            'code' => strtoupper(trim($data['code'])), 'duty' => trim($data['duty']), 'description' => $data['description'] ?? null,
            'can_punch' => $working, 'start_time' => $working ? $data['start_time'] : null, 'end_time' => $working ? $data['end_time'] : null,
            'pass_day' => (bool) $day?->crossesMidnight, 'work_hour' => $day?->hours ?? 0, 'break_minutes' => $working ? (int) ($data['break_minutes'] ?? 0) : 0, 'color' => $data['color'] ?? null,
            'is_active' => $data['is_active'] ?? $code?->is_active ?? true,
        ];
    }
}
