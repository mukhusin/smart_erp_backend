<?php

namespace Modules\Hr\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\UploadedPayroll;
use App\Models\UploadedPayrollRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\PdfDocument;
use Modules\Hr\Support\Payslip;

/**
 * Salary files uploaded by payroll (the HRMS way): a CSV with one row per employee and what was paid. Employees see
 * them as payslips, printed with the same salary slip as payroll-made payslips.
 *
 * @tags Payroll: Salary files
 */
class SalaryFileController extends Controller
{
    private const TEXT = ['ST_NO', 'NAME', 'TIN', 'NSSFA_CNO', 'DESG', 'EMAIL_ADDRESS'];

    /**
     * Uploaded salary files, newest month first.
     *
     * @response array{data: list<array{id: int, year: int, month: int, label: string, file_name: string|null, rows: int, net_total: float, uploaded_by: string|null, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:20'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = UploadedPayroll::with('uploader')->withCount('rows')->withSum('rows', 'net_pay')
            ->when(trim((string) ($f['search'] ?? '')), fn ($q, $v) => $q->where('year', 'like', "%{$v}%"))
            ->orderByDesc('year')->orderByDesc('month')->paginate((int) ($f['per_page'] ?? 10));

        return response()->json([
            'data' => collect($page->items())->map(fn ($u) => [
                'id' => $u->id, 'year' => (int) $u->year, 'month' => (int) $u->month, 'label' => Carbon::create($u->year, $u->month, 1)->format('F Y'),
                'file_name' => $u->file_name, 'rows' => (int) $u->rows_count, 'net_total' => round((float) $u->rows_sum_net_pay, 2),
                'uploaded_by' => $u->uploader?->full_name, 'created_at' => $u->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * Download the CSV template: the salary file columns, with a row per active employee (number, name, TIN, NSSF number, position, basic salary).
     */
    public function template(): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, Payslip::TEMPLATE);
        $employees = Employee::query()->active()->staff()->with(['jobPosition', 'activeContract'])->orderBy('employee_no')->get();
        $nssf = DB::table('nssfs')->whereIn('employee_id', $employees->pluck('id'))->pluck('contribution_no', 'employee_id');
        foreach ($employees as $e) {
            $row = array_fill_keys(Payslip::TEMPLATE, '');
            $row['ST_NO'] = $e->employee_no;
            $row['NAME'] = $e->full_name;
            $row['TIN'] = $e->tin;
            $row['NSSFA_CNO'] = $e->nssf_no ?: ($nssf[$e->id] ?? '');
            $row['DESG'] = $e->jobPosition?->title;
            $row['BASIC_SALARY'] = $e->activeContract?->basic_salary;
            fputcsv($out, array_values($row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="salary-file-template.csv"']);
    }

    /**
     * Upload a month's salary file (CSV with the template columns). Every row must match an employee by number.
     * A month that already has a file is replaced only with `replace`.
     *
     * @response array{data: array{id: int, label: string, rows: int, net_total: float}}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], 'year' => ['required', 'integer', 'between:2000,2100'], 'month' => ['required', 'integer', 'between:1,12'],
            'replace' => ['boolean'],
        ]);
        $existing = UploadedPayroll::where('year', $d['year'])->where('month', $d['month'])->first();
        $label = Carbon::create($d['year'], $d['month'], 1)->format('F Y');
        if ($existing && empty($d['replace'])) {
            throw ValidationException::withMessages(['month' => "There is already a salary file for {$label}. Choose to replace it."]);
        }

        [$rows, $problems] = $this->parse($request->file('file')->getRealPath());
        if ($problems) {
            throw ValidationException::withMessages(['file' => array_slice($problems, 0, 30)]);
        }

        $me = $request->user()->employee;
        $upload = DB::transaction(function () use ($existing, $d, $rows, $request, $me) {
            if ($existing) {
                $existing->rows()->delete();
                $existing->delete();
            }
            $upload = UploadedPayroll::create(['year' => $d['year'], 'month' => $d['month'], 'file_name' => $request->file('file')->getClientOriginalName(), 'uploaded_by' => $me?->id]);
            foreach ($rows as $r) {
                $upload->rows()->create($r);
            }

            return $upload;
        });

        return response()->json(['data' => ['id' => $upload->id, 'label' => $label, 'rows' => count($rows), 'net_total' => round(array_sum(array_column($rows, 'net_pay')), 2)]], 201);
    }

    /**
     * The rows of a salary file.
     *
     * @response array{data: list<array{id: int, employee_id: int|null, employee_no: string|null, name: string|null, position: string|null, gross: float|null, net: float|null}>, meta: array{label: string, current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    public function rows(Request $request, UploadedPayroll $upload): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'in:employee_no,-employee_no,net,-net,gross,-gross'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $sort = $f['sort'] ?? 'employee_no';
        $column = ['employee_no' => 'employee_no', 'net' => 'net_pay', 'gross' => 'gross_salary'][ltrim($sort, '-')];
        $page = $upload->rows()->with('employee')
            ->when(trim((string) ($f['search'] ?? '')), fn ($q, $v) => $q->where(fn ($q) => $q->where('employee_no', 'like', "%{$v}%")
                ->orWhereIn('employee_id', Employee::withoutGlobalScopes()->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->select('id'))))
            ->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')->paginate((int) ($f['per_page'] ?? 10));

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'id' => $r->id, 'employee_id' => $r->employee_id, 'employee_no' => $r->employee?->employee_no ?? $r->employee_no,
                'name' => $r->employee?->full_name ?? ($r->data['NAME'] ?? null), 'position' => $r->data['DESG'] ?? null, 'gross' => $r->gross_salary, 'net' => $r->net_pay,
            ])->values(),
            'meta' => ['label' => Carbon::create($upload->year, $upload->month, 1)->format('F Y'), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * Print one employee's payslip from the salary file.
     */
    public function pdf(Request $request, UploadedPayroll $upload, UploadedPayrollRow $row, Payslip $payslip, PdfDocument $pdf): Response
    {
        abort_unless($row->uploaded_payroll_id === $upload->id, 404);

        return app(PayrollController::class)->printData($request, $payslip->forRow($row), $pdf);
    }

    /**
     * Delete a salary file (its payslips disappear for employees).
     */
    public function destroy(UploadedPayroll $upload): Response
    {
        DB::transaction(function () use ($upload) {
            $upload->rows()->delete();
            $upload->delete();
        });

        return response()->noContent();
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            return [[], ['The file is empty.']];
        }
        $header = array_map(fn ($h) => strtoupper(trim(str_replace([' ', '-'], '_', preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)))), $header);
        if (! in_array('ST_NO', $header, true) || ! array_intersect(['NET_PAY', 'NET_TO_BANK'], $header)) {
            return [[], ['The first row must have the template columns: at least ST_NO and NET_PAY.']];
        }
        $employees = Employee::query()->get(['id', 'employee_no']);
        $exact = $employees->filter(fn ($e) => $e->employee_no)->mapWithKeys(fn ($e) => [strtoupper(trim($e->employee_no)) => $e->id]);
        $digits = $employees->filter(fn ($e) => $e->employee_no)->groupBy(fn ($e) => preg_replace('/\D/', '', $e->employee_no))->filter(fn ($g) => $g->count() === 1)->map(fn ($g) => $g->first()->id);

        $rows = $problems = $seen = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $data = [];
            foreach ($header as $i => $col) {
                $value = trim((string) ($cells[$i] ?? ''));
                $data[$col] = in_array($col, self::TEXT, true) ? $value : ($value === '' ? '0.00' : str_replace([',', ' '], '', $value));
                if (! in_array($col, self::TEXT, true) && $col !== '' && ! is_numeric($data[$col])) {
                    $problems[] = "Line {$line}: {$col} \"{$value}\" is not a number.";
                }
            }
            $no = strtoupper($data['ST_NO'] ?? '');
            $employeeId = $exact[$no] ?? (($d = preg_replace('/\D/', '', $no)) !== '' ? ($digits[$d] ?? null) : null);
            if (! $employeeId) {
                $problems[] = "Line {$line}: no employee with number \"{$data['ST_NO']}\".";

                continue;
            }
            if (isset($seen[$employeeId])) {
                $problems[] = "Line {$line}: {$data['ST_NO']} is already on line {$seen[$employeeId]}.";

                continue;
            }
            $seen[$employeeId] = $line;
            $net = is_numeric($data['NET_PAY'] ?? null) && (float) $data['NET_PAY'] != 0 ? (float) $data['NET_PAY'] : (float) ($data['NET_TO_BANK'] ?? 0) + (float) ($data['CASH'] ?? 0);
            $rows[] = ['employee_id' => $employeeId, 'employee_no' => $data['ST_NO'], 'gross_salary' => is_numeric($data['GROSS_SALARY'] ?? null) ? (float) $data['GROSS_SALARY'] : null,
                'net_pay' => $net, 'data' => $data];
        }
        fclose($handle);
        if (! $rows && ! $problems) {
            $problems[] = 'The file has no rows.';
        }

        return [$rows, $problems];
    }
}
