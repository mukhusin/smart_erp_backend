<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Core\Support\PdfDocument;
use Modules\Hr\Support\HrReports;
use Modules\Hr\Support\HrStats;

/**
 * @tags HR: Lookups
 */
class HrStatsController extends Controller
{
    /**
     * The records behind an HR dashboard number.
     *
     * `stat`: employees, probation, confirmed, female, male, type (`value` = employment type), without_account, invited,
     * without_contract, contracts_ending, licences_expiring, department (`value` = id), position (`value` = id),
     * placements_active, placements_ending, placements_upcoming, placements_internship, placements_residency,
     * renewals_pending, renewals_approved, renewals_renewed, renewals_rejected, departments, sections, departments_staffed,
     * positions, positions_filled. Rows may carry `employee_id`, `department_id` or `position_id` for drilling down.
     *
     * @response array{data: array{key: string, title: string, subtitle: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, total: int}}
     */
    #[QueryParameter('value', type: 'string')]
    public function show(Request $request, string $stat, HrStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->run($stat, $request->query('value'), $request->user())]);
    }

    /**
     * Download the records behind an HR dashboard number as CSV (opens in Excel).
     */
    #[QueryParameter('value', type: 'string')]
    public function csv(Request $request, string $stat, HrStats $stats, HrReports $reports): Response
    {
        $result = $stats->run($stat, $request->query('value'), $request->user());
        $name = Str::slug($result['title']).'-'.now()->format('Y-m-d').'.csv';

        return response($reports->csv($result), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    /**
     * The records behind an HR dashboard number as a PDF with the company header (opens in the browser).
     */
    #[QueryParameter('value', type: 'string')]
    public function pdf(Request $request, string $stat, HrStats $stats, PdfDocument $pdf): Response
    {
        $result = $stats->run($stat, $request->query('value'), $request->user());

        return $pdf->keyed($request->user(), $result['title'], $result['subtitle'], $result['columns'], $result['rows']);
    }
}
