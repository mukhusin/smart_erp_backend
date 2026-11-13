<?php

namespace Modules\Hr\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Actions\ManagePayroll;
use Modules\Hr\Support\PayrollCalculator;
use Modules\Hr\Support\PayrollReports;

/**
 * Payroll: settings, monthly payroll sheets and payslips.
 *
 * @tags Payroll
 */
class PayrollController extends Controller
{
    public function __construct(private readonly ManagePayroll $payroll) {}

    /**
     * Payroll settings: period, statutory rates, PAYE bands and what payroll includes.
     *
     * @response array{data: array{from_day: int, from_month: string, to_day: int, to_month: string, wcf: float, sdl: float, income_tax: float, emergency_fund: float, nssf_employee: float, nssf_employer: float, nhif_employee: float, nhif_employer: float, heslb: float, tax_bands: list<array{up_to: float|null, rate: float}>, use_overtime: bool, use_loan: bool, use_allowance: bool, use_leave_deduction: bool, use_deduction: bool, use_saccos: bool, use_relief: bool, use_attendance: bool, example_period: string}}
     */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settingsRow(PayrollDateSetting::current())]);
    }

    /**
     * Save the payroll settings. Draft payrolls follow them when worked out again.
     *
     * @response array{data: array{from_day: int, from_month: string, to_day: int, to_month: string, wcf: float, sdl: float, income_tax: float, emergency_fund: float, nssf_employee: float, nssf_employer: float, nhif_employee: float, nhif_employer: float, heslb: float, tax_bands: list<array{up_to: float|null, rate: float}>, use_overtime: bool, use_loan: bool, use_allowance: bool, use_leave_deduction: bool, use_deduction: bool, use_saccos: bool, use_relief: bool, use_attendance: bool, example_period: string}}
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'from_day' => ['required', 'integer', 'between:1,31'], 'from_month' => ['required', 'in:last,current'],
            'to_day' => ['required', 'integer', 'between:1,31'], 'to_month' => ['required', 'in:current,next'],
            /** Employer, % of gross. */
            'wcf' => ['required', 'numeric', 'between:0,100'],
            /** Employer, % of gross. */
            'sdl' => ['required', 'numeric', 'between:0,100'],
            /** Flat PAYE for secondary employment, %. */
            'income_tax' => ['required', 'numeric', 'between:0,100'],
            /** Default rates offered when adding statutory memberships. */
            'nssf_employee' => ['required', 'numeric', 'between:0,100'], 'nssf_employer' => ['required', 'numeric', 'between:0,100'],
            'nhif_employee' => ['required', 'numeric', 'between:0,100'], 'nhif_employer' => ['required', 'numeric', 'between:0,100'],
            'heslb' => ['required', 'numeric', 'between:0,100'], 'emergency_fund' => ['required', 'numeric', 'min:0'],
            /** PAYE bands: each band's upper amount (null for the last) and the rate on the part above the previous band. */
            'tax_bands' => ['required', 'array', 'min:1', 'max:12'],
            'tax_bands.*.up_to' => ['nullable', 'numeric', 'min:0'],
            'tax_bands.*.rate' => ['required', 'numeric', 'between:0,100'],
            'use_overtime' => ['boolean'], 'use_loan' => ['boolean'], 'use_allowance' => ['boolean'], 'use_leave_deduction' => ['boolean'],
            'use_deduction' => ['boolean'], 'use_saccos' => ['boolean'], 'use_relief' => ['boolean'],
            /** Deduct lost time from biometric attendance (only when attendance is turned on, for tracked employees). */
            'use_attendance' => ['boolean'],
        ]);
        $bands = collect($d['tax_bands'])->map(fn ($b) => ['up_to' => $b['up_to'] === null || $b['up_to'] === '' ? null : (float) $b['up_to'], 'rate' => (float) $b['rate']])->values();
        $bands = $bands->sortBy(fn ($b) => $b['up_to'] ?? INF)->values();
        if ($bands->whereNull('up_to')->count() !== 1 || $bands->last()['up_to'] !== null) {
            throw ValidationException::withMessages(['tax_bands' => 'The last PAYE band has no upper amount, and only the last.']);
        }
        $setting = PayrollDateSetting::query()->first() ?? new PayrollDateSetting(PayrollDateSetting::DEFAULTS);
        $setting->fill(['tax_bands' => $bands->all(), 'updated_by' => $request->user()->employee?->id] + collect($d)->except('tax_bands')->all())->save();

        return response()->json(['data' => $this->settingsRow($setting)]);
    }

    /**
     * Payroll sheets, newest first.
     *
     * @response array{data: list<array{id: int, name: string, year: int, month: int, from: string, to: string, days: int, status: string, status_label: string, employees: int, gross: float, deductions: float, net: float, employer: float, cost: float, waiting_for: string|null, decision_comment: string|null, generated_at: string|null}>}
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => PayrollSheet::with('workflow.steps')->get()->sortByDesc(fn ($s) => sprintf('%04d%02d', $s->year, $s->month))->take(60)->values()->map(fn ($s) => $this->sheetRow($s))]);
    }

    /**
     * Create the payroll of a month and work it out.
     *
     * @response array{data: array{id: int, name: string, year: int, month: int, from: string, to: string, days: int, status: string, status_label: string, employees: int, gross: float, deductions: float, net: float, employer: float, cost: float, waiting_for: string|null, decision_comment: string|null, generated_at: string|null}}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12']]);

        return response()->json(['data' => $this->sheetRow($this->payroll->create((int) $d['year'], (int) $d['month'], $request->user()))], 201);
    }

    /**
     * A payroll sheet with each employee's pay.
     *
     * @response array{data: array{sheet: array{id: int, name: string, year: int, month: int, from: string, to: string, days: int, status: string, status_label: string, employees: int, gross: float, deductions: float, net: float, employer: float, cost: float, waiting_for: string|null, decision_comment: string|null, generated_at: string|null}, rows: list<array{id: int, employee_id: int, name: string, employee_no: string|null, position: string|null, department: string|null, days_paid: float|null, unpaid_days: float, basic: float, allowances: float, overtime: float, relief: float, gross: float, nssf: float, nhif: float, heslb: float, paye: float, loans: float, other_deductions: float, deductions: float, net: float, bank: string|null, account: string|null}>, totals: array<string, float>, can_edit: bool}}
     */
    public function show(PayrollSheet $sheet): JsonResponse
    {
        $sheet->load('workflow.steps');
        $rows = $sheet->details()->with('employee.department')->orderBy('full_name')->get()->map(fn (PayrollSheetDetail $d) => [
            'id' => $d->id, 'employee_id' => $d->employee_id, 'name' => $d->full_name, 'employee_no' => $d->employee?->employee_no, 'position' => $d->job_position,
            'department' => $d->employee?->department?->name, 'days_paid' => $d->days_paid, 'unpaid_days' => (float) $d->unpaid_days,
            'basic' => $d->basic_salary, 'allowances' => $d->total_allowance, 'overtime' => $d->total_overtime, 'relief' => $d->relief, 'gross' => $d->gross_salary,
            'nssf' => $d->nssf, 'nhif' => $d->nhif, 'heslb' => $d->heslb, 'paye' => $d->paye, 'loans' => round($d->total_loan + $d->advance, 2),
            'other_deductions' => round($d->tughe + $d->nic + $d->ef + $d->saccos_serving + (float) $d->attendance_deduction, 2), 'deductions' => $d->total_deduction, 'net' => $d->net_salary,
            'bank' => $d->bank_name, 'account' => $d->bank_account,
        ]);
        $totals = collect(['basic', 'allowances', 'overtime', 'relief', 'gross', 'nssf', 'nhif', 'heslb', 'paye', 'loans', 'other_deductions', 'deductions', 'net'])
            ->mapWithKeys(fn ($k) => [$k => round((float) $rows->sum($k), 2)]);

        return response()->json(['data' => ['sheet' => $this->sheetRow($sheet), 'rows' => $rows->values(), 'totals' => $totals, 'can_edit' => $sheet->editable()]]);
    }

    /**
     * Work a draft payroll out again (after changes to contracts, allowances, overtime, leave or deductions).
     *
     * @response array{data: array{id: int, name: string, year: int, month: int, from: string, to: string, days: int, status: string, status_label: string, employees: int, gross: float, deductions: float, net: float, employer: float, cost: float, waiting_for: string|null, decision_comment: string|null, generated_at: string|null}}
     */
    public function regenerate(PayrollSheet $sheet): JsonResponse
    {
        return response()->json(['data' => $this->sheetRow($this->payroll->generate($sheet))]);
    }

    /**
     * Submit for approval (the "Payroll sheet" workflow; approved at once without one).
     *
     * @response array{data: array{id: int, name: string, year: int, month: int, from: string, to: string, days: int, status: string, status_label: string, employees: int, gross: float, deductions: float, net: float, employer: float, cost: float, waiting_for: string|null, decision_comment: string|null, generated_at: string|null}}
     */
    public function submit(Request $request, PayrollSheet $sheet): JsonResponse
    {
        return response()->json(['data' => $this->sheetRow($this->payroll->submit($sheet, $request->user())->load('workflow.steps'))]);
    }

    /**
     * Withdraw a payroll waiting for approval, back to draft.
     *
     * @response array{data: array{id: int, name: string, year: int, month: int, from: string, to: string, days: int, status: string, status_label: string, employees: int, gross: float, deductions: float, net: float, employer: float, cost: float, waiting_for: string|null, decision_comment: string|null, generated_at: string|null}}
     */
    public function withdraw(Request $request, PayrollSheet $sheet): JsonResponse
    {
        return response()->json(['data' => $this->sheetRow($this->payroll->withdraw($sheet, $request->user()))]);
    }

    /** Delete a draft or returned payroll. */
    public function destroy(PayrollSheet $sheet): Response
    {
        $this->payroll->delete($sheet);

        return response()->noContent();
    }

    /**
     * An employee's payslip on a payroll sheet.
     *
     * @response array{data: array{sheet: string, period: string, status: string, employee: array{name: string, employee_no: string|null, position: string|null, department: string|null, bank: string|null, account: string|null}, earnings: list<array{label: string, amount: float}>, deductions: list<array{label: string, amount: float}>, employer: list<array{label: string, amount: float}>, gross: float, taxable: float, total_deductions: float, net: float}}
     */
    public function payslip(PayrollSheet $sheet, PayrollSheetDetail $detail): JsonResponse
    {
        abort_unless($detail->payroll_sheet_id === $sheet->id, 404);

        return response()->json(['data' => $this->slip($detail->load('employee.department', 'payrollSheet'))]);
    }

    /**
     * My payslips (approved payrolls).
     *
     * @response array{data: list<array{id: int, sheet: string, year: int, month: int, gross: float, deductions: float, net: float}>}
     */
    public function myPayslips(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $rows = PayrollSheetDetail::with('payrollSheet')->where('employee_id', $employee->id)
            ->whereHas('payrollSheet', fn ($q) => $q->where('status', 'approved'))->get()
            ->sortByDesc(fn ($d) => sprintf('%04d%02d', $d->payrollSheet->year, $d->payrollSheet->month))->values();

        return response()->json(['data' => $rows->map(fn ($d) => ['id' => $d->id, 'sheet' => $d->payrollSheet->name, 'year' => (int) $d->payrollSheet->year,
            'month' => (int) $d->payrollSheet->month, 'gross' => $d->gross_salary, 'deductions' => $d->total_deduction, 'net' => $d->net_salary])]);
    }

    /**
     * One of my payslips.
     *
     * @response array{data: array{sheet: string, period: string, status: string, employee: array{name: string, employee_no: string|null, position: string|null, department: string|null, bank: string|null, account: string|null}, earnings: list<array{label: string, amount: float}>, deductions: list<array{label: string, amount: float}>, employer: list<array{label: string, amount: float}>, gross: float, taxable: float, total_deductions: float, net: float}}
     */
    public function myPayslip(Request $request, PayrollSheetDetail $detail): JsonResponse
    {
        abort_unless($detail->employee_id === $this->me($request)->id && $detail->payrollSheet?->status === 'approved', 404);

        return response()->json(['data' => $this->slip($detail->load('employee.department', 'payrollSheet'))]);
    }

    /**
     * Work out the PAYE of a taxable income with the current bands (to check the settings).
     *
     * @response array{data: array{taxable: float, paye: float}}
     */
    #[QueryParameter('taxable', type: 'number', required: true)]
    public function payeCheck(Request $request): JsonResponse
    {
        $taxable = (float) $request->validate(['taxable' => ['required', 'numeric', 'min:0']])['taxable'];

        return response()->json(['data' => ['taxable' => $taxable, 'paye' => PayrollCalculator::paye($taxable, PayrollDateSetting::current()->bands())]]);
    }

    /**
     * A payroll report as a table: bank, paye, nssf, nhif, heslb, employer or full. `bank_id` limits the bank list to one bank.
     *
     * @response array{data: array{title: string, columns: list<string>, rows: list<list<string|float|null>>, totals: list<string|float|null>, types: array<string, string>, banks: list<array{id: int, name: string, employees: int, amount: float}>, missing_bank: int}}
     */
    #[QueryParameter('bank_id', type: 'integer')]
    public function report(Request $request, PayrollSheet $sheet, string $type): JsonResponse
    {
        abort_unless(array_key_exists($type, PayrollReports::TYPES), 404);
        $bankId = $request->validate(['bank_id' => ['nullable', 'integer']])['bank_id'] ?? null;
        $details = $sheet->details()->get(['bank_id', 'bank_name', 'net_salary', 'bank_account']);

        return response()->json(['data' => app(PayrollReports::class)->build($sheet, $type, $bankId) + [
            'types' => PayrollReports::TYPES,
            'banks' => $details->whereNotNull('bank_id')->groupBy('bank_id')->map(fn ($g, $id) => ['id' => (int) $id, 'name' => (string) $g->first()->bank_name, 'employees' => $g->count(), 'amount' => round((float) $g->sum('net_salary'), 2)])->sortBy('name')->values(),
            'missing_bank' => $details->filter(fn ($d) => ! $d->bank_account)->count(),
        ]]);
    }

    /** Download a payroll report as a CSV file (opens in Excel). */
    #[QueryParameter('bank_id', type: 'integer')]
    public function reportCsv(Request $request, PayrollSheet $sheet, string $type): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(array_key_exists($type, PayrollReports::TYPES), 404);
        $reports = app(PayrollReports::class);
        $report = $reports->build($sheet, $type, $request->integer('bank_id') ?: null);
        $name = Str::slug($sheet->name.' '.$report['title']).'.csv';

        return response($reports->csv($report), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    private function slip(PayrollSheetDetail $d): array
    {
        $lines = collect($d->lines ?? []);
        $pick = fn ($g) => $lines->where('group', $g)->map(fn ($l) => ['label' => $l['label'], 'amount' => (float) $l['amount']])->values()->all();
        $s = $d->payrollSheet;

        return [
            'sheet' => $s->name, 'period' => $s->payroll_from_date->format('d M Y').' – '.$s->payroll_to_date->format('d M Y'), 'status' => $s->status,
            'employee' => ['name' => $d->full_name, 'employee_no' => $d->employee?->employee_no, 'position' => $d->job_position, 'department' => $d->employee?->department?->name,
                'bank' => $d->bank_name, 'account' => $d->bank_account],
            'earnings' => $pick('earning'), 'deductions' => $pick('deduction'), 'employer' => $pick('employer'),
            'gross' => $d->gross_salary, 'taxable' => $d->amount_taxable, 'total_deductions' => $d->total_deduction, 'net' => $d->net_salary,
        ];
    }

    private function sheetRow(PayrollSheet $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'year' => (int) $s->year, 'month' => (int) $s->month, 'from' => $s->payroll_from_date->toDateString(), 'to' => $s->payroll_to_date->toDateString(),
            'days' => (int) $s->number_of_days, 'status' => $s->status, 'status_label' => PayrollSheet::STATUS_LABELS[$s->status] ?? $s->status, 'employees' => (int) $s->employees_count,
            'gross' => (float) $s->gross_total, 'deductions' => (float) $s->deduction_total, 'net' => (float) $s->net_total, 'employer' => (float) $s->employer_total,
            'cost' => (float) $s->payroll_cost, 'waiting_for' => $s->status === 'pending' ? $s->workflow?->steps?->firstWhere('status', 'pending')?->name : null,
            'decision_comment' => $s->decision_comment, 'generated_at' => $s->generated_at?->toIso8601String(),
        ];
    }

    private function settingsRow(PayrollDateSetting $s): array
    {
        [$from, $to] = $s->period(now()->year, now()->month);

        return [
            'from_day' => (int) $s->from_day, 'from_month' => $s->from_month, 'to_day' => (int) $s->to_day, 'to_month' => $s->to_month,
            'wcf' => (float) $s->wcf, 'sdl' => (float) $s->sdl, 'income_tax' => (float) $s->income_tax, 'emergency_fund' => (float) $s->emergency_fund,
            'nssf_employee' => (float) $s->nssf_employee, 'nssf_employer' => (float) $s->nssf_employer, 'nhif_employee' => (float) $s->nhif_employee,
            'nhif_employer' => (float) $s->nhif_employer, 'heslb' => (float) $s->heslb, 'tax_bands' => $s->bands(),
            'use_overtime' => (bool) $s->use_overtime, 'use_loan' => (bool) $s->use_loan, 'use_allowance' => (bool) $s->use_allowance,
            'use_leave_deduction' => (bool) $s->use_leave_deduction, 'use_deduction' => (bool) $s->use_deduction, 'use_saccos' => (bool) $s->use_saccos,
            'use_relief' => (bool) $s->use_relief, 'use_attendance' => (bool) $s->use_attendance, 'example_period' => now()->format('F Y').': '.$from->format('d M').' – '.$to->format('d M Y'),
        ];
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
