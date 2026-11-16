<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\FiscalYearRequest;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Services\FiscalCalendar;

/**
 * @tags Accounting: setup
 */
/**
 * @tags Accounting: fiscal years
 */
class FiscalYearController extends Controller
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    public function fiscalYears(): JsonResponse
    {
        $years = FiscalYear::with('periods')->orderByDesc('start_date')->get();
        $fys = app(CompanyContext::class)->get()?->fiscal_year_start ?: '01-01';
        $next = $years->first()?->end_date?->copy()->addDay()->toDateString() ?? now()->year.'-'.$fys;

        return response()->json(['data' => $years->map(fn ($y) => $this->yearRow($y))->values(), 'next_start' => $next]);
    }

    public function storeFiscalYear(FiscalYearRequest $request): JsonResponse
    {
        $d = $request->validated();

        return response()->json(['data' => $this->yearRow($this->calendar->createYear($d['start_date'], $d['name'] ?? null))], 201);
    }

    public function closePeriod(Request $request, FiscalPeriod $period): JsonResponse
    {
        abort_unless($period->year?->company_id === app(CompanyContext::class)->id(), 404);
        $p = $this->calendar->close($period, $request->user());

        return response()->json(['data' => ['id' => $p->id, 'status' => $p->status]]);
    }

    public function reopenPeriod(FiscalPeriod $period): JsonResponse
    {
        abort_unless($period->year?->company_id === app(CompanyContext::class)->id(), 404);
        $p = $this->calendar->reopen($period);

        return response()->json(['data' => ['id' => $p->id, 'status' => $p->status]]);
    }

    private function yearRow(FiscalYear $y): array
    {
        return ['id' => $y->id, 'name' => $y->name, 'start_date' => $y->start_date->toDateString(), 'end_date' => $y->end_date->toDateString(), 'status' => $y->status,
            'periods' => $y->periods->map(fn ($p) => ['id' => $p->id, 'number' => (int) $p->number, 'name' => $p->name, 'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(), 'status' => $p->status, 'closed_at' => $p->closed_at?->toIso8601String()])->values()];
    }
}
