<?php

namespace Modules\Reporting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Core\Support\PdfDocument;
use Modules\Hr\Support\HrReports;
use Modules\Reporting\Support\FinanceReports;

/**
 * @tags Reports
 */
class FinanceReportsController extends Controller
{
    /**
     * Finance and planning reports the user may run (accounting, payroll, loans, budgeting, planning), with their filters.
     *
     * @response array{data: list<array{key: string, title: string, group: string, filters: list<array{key: string, label: string, type: string, default?: string, hint?: string, options?: list<array{value: string, label: string}>}>}>}
     */
    public function index(Request $request, FinanceReports $reports): JsonResponse
    {
        return response()->json(['data' => $reports->catalog($request->user())]);
    }

    /**
     * Run a finance report. Pass its filters as query parameters.
     *
     * @response array{data: array{key: string, title: string, subtitle: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, total: int}}
     */
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('date', type: 'string')]
    #[QueryParameter('year', type: 'integer')]
    #[QueryParameter('bank_id', type: 'integer')]
    #[QueryParameter('budget_id', type: 'integer')]
    #[QueryParameter('plan_id', type: 'integer')]
    #[QueryParameter('sheet_id', type: 'integer')]
    #[QueryParameter('provider_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('group', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('kind', type: 'string')]
    #[QueryParameter('type', type: 'string')]
    #[QueryParameter('store_id', type: 'integer')]
    #[QueryParameter('fund_source_id', type: 'integer')]
    #[QueryParameter('stage', description: 'Trial balance: `adjusted` or `unadjusted`.', type: 'string')]
    public function show(Request $request, string $report, FinanceReports $reports): JsonResponse
    {
        return response()->json(['data' => $reports->run($report, $this->filters($request), $request->user())]);
    }

    /** Download a finance report as CSV (opens in Excel). */
    public function csv(Request $request, string $report, FinanceReports $reports, HrReports $hr): Response
    {
        $result = $reports->run($report, $this->filters($request), $request->user());
        $name = Str::slug($result['title']).'-'.now()->format('Y-m-d').'.csv';

        return response($hr->csv($result), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /** A finance report as a PDF with the company header. */
    public function pdf(Request $request, string $report, FinanceReports $reports, PdfDocument $pdf): Response
    {
        $result = $reports->run($report, $this->filters($request), $request->user());

        return $pdf->keyed($request->user(), $result['title'], $result['subtitle'], $result['columns'], $result['rows']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'date' => ['nullable', 'date'], 'year' => ['nullable', 'integer', 'between:2000,2100'],
            'bank_id' => ['nullable', 'integer'], 'budget_id' => ['nullable', 'integer'], 'plan_id' => ['nullable', 'integer'], 'sheet_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'group' => ['nullable', 'in:department,account,activity,fund,item'],
            'status' => ['nullable', 'string', 'max:30'], 'kind' => ['nullable', 'string', 'max:30'], 'type' => ['nullable', 'string', 'max:30'], 'store_id' => ['nullable', 'integer'],
            'fund_source_id' => ['nullable', 'integer'], 'stage' => ['nullable', 'in:adjusted,unadjusted'],
        ]);
    }
}
