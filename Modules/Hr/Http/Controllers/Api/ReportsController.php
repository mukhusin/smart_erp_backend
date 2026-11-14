<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Core\Support\PdfDocument;
use Modules\Hr\Support\HrOverview;
use Modules\Hr\Support\HrReports;

/**
 * @tags Reports
 */
class ReportsController extends Controller
{
    /**
     * Management overview: staff numbers, movement over 12 months, leave, contracts; salary bill, payroll and today's
     * attendance only when the user may see them (otherwise null). Limited to the user's employee scope.
     */
    public function overview(Request $request, HrOverview $overview): JsonResponse
    {
        return response()->json(['data' => $overview->build($request->user())]);
    }

    /**
     * Available reports with their filters (`type`: date, number or select; `default` is used when empty).
     */
    public function index(Request $request, HrReports $reports): JsonResponse
    {
        return response()->json(['data' => $reports->catalog($request->user())]);
    }

    /**
     * Run a report. Pass the report's filters as query parameters.
     *
     * @response array{data: array{key: string, title: string, subtitle: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, total: int}}
     */
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('date', type: 'string')]
    #[QueryParameter('month', type: 'integer')]
    #[QueryParameter('age', type: 'integer')]
    #[QueryParameter('months', type: 'integer')]
    #[QueryParameter('days', type: 'integer')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('reason', type: 'string')]
    #[QueryParameter('type', type: 'string')]
    #[QueryParameter('emp_type', type: 'string')]
    #[QueryParameter('licence_id', type: 'integer')]
    #[QueryParameter('leave_type_id', type: 'integer')]
    #[QueryParameter('branch_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    public function show(Request $request, string $report, HrReports $reports): JsonResponse
    {
        return response()->json(['data' => $reports->run($report, $this->filters($request), $request->user())]);
    }

    /**
     * Download a report as CSV (opens in Excel). Same filters as showing it.
     */
    public function csv(Request $request, string $report, HrReports $reports): Response
    {
        $result = $reports->run($report, $this->filters($request), $request->user());
        $name = Str::slug($result['title']).'-'.now()->format('Y-m-d').'.csv';

        return response($reports->csv($result), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /**
     * A report as a PDF with the company header (opens in the browser). Same filters as showing it.
     */
    public function pdf(Request $request, string $report, HrReports $reports, PdfDocument $pdf): Response
    {
        $result = $reports->run($report, $this->filters($request), $request->user());

        return $pdf->keyed($request->user(), $result['title'], $result['subtitle'], $result['columns'], $result['rows']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'age' => ['nullable', 'integer', 'between:40,80'],
            'months' => ['nullable', 'integer', 'between:0,120'],
            'days' => ['nullable', 'integer', 'between:0,730'],
            'status' => ['nullable', 'string', 'max:50'],
            'leave_type_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:50'],
            'emp_type' => ['nullable', 'string', 'max:50'],
            'licence_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ]);
    }
}
