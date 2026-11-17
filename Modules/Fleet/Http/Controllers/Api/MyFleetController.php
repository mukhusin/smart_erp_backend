<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Models\Compliance;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;

/**
 * @tags Fleet: self service
 */
class MyFleetController extends Controller
{
    /**
     * Vehicles assigned to me as driver, with their status and compliance, and my driving licence.
     *
     * @response array{data: array{driver: bool, licence: array{number: string, classes: list<string>, expires_on: string, days_left: int}|null, vehicles: list<array{id: int, label: string, type: string|null, status: string, status_label: string, reading: float, unit: string, since: string, until: string|null, compliance: list<array{type: string, expires_on: string, days_left: int}>}>}}
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $driver = $employee ? Driver::with(['licence', 'assignments' => fn ($q) => $q->whereNull('ended_on')->with('unit.type', 'unit.compliance')])->where('employee_id', $employee->id)->first() : null;
        $l = $driver?->licence;

        return response()->json(['data' => [
            'driver' => (bool) $driver,
            'licence' => $l ? ['number' => $l->number, 'classes' => $l->classes, 'expires_on' => $l->expires_on->toDateString(), 'days_left' => (int) today()->diffInDays($l->expires_on, false)] : null,
            'vehicles' => ($driver?->assignments ?? collect())->filter(fn ($a) => $a->unit)->map(fn ($a) => ['id' => $a->unit->id, 'label' => $a->unit->label(), 'type' => $a->unit->type?->name,
                'status' => $a->unit->status, 'status_label' => FleetUnit::STATUSES[$a->unit->status] ?? $a->unit->status, 'reading' => $a->unit->reading, 'unit' => $a->unit->unit(),
                'since' => $a->starts_on->toDateString(), 'until' => $a->ends_on?->toDateString(),
                'compliance' => $a->unit->compliance->where('is_current', true)->map(fn ($c) => ['type' => Compliance::TYPES[$c->type] ?? $c->type, 'expires_on' => $c->expires_on->toDateString(),
                    'days_left' => (int) today()->diffInDays($c->expires_on, false)])->values()])->values(),
        ]]);
    }
}
