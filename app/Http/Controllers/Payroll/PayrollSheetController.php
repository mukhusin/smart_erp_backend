<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\AllowanceSheet;
use App\Models\LoanHistory;
use App\Models\OvertimeSheet;
use App\Models\PayrollComment;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Services\CompanyContext;
use App\Services\PayrollComputationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PayrollSheetController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = PayrollSheet::where('company_id', $this->ctx->id());

        if ($request->filled('year'))   $query->where('year', $request->year);
        if ($request->filled('status')) $query->where('status', $request->status);

        $sheets = $query->orderByDesc('year')->orderByDesc('id')->paginate(20)->withQueryString();
        return view('payroll.sheets.index', compact('sheets'));
    }

    public function create()
    {
        $setting = PayrollDateSetting::where('company_id', $this->ctx->id())
            ->where('is_active', true)->first();

        return view('payroll.sheets.create', compact('setting'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'month'             => 'required|string',
            'year'              => 'required|string|max:4',
            'payroll_from_date' => 'required|date',
            'payroll_to_date'   => 'required|date|after_or_equal:payroll_from_date',
            'number_of_days'    => 'required|integer|min:1|max:31',
        ]);

        $sheet = PayrollSheet::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'status'     => 'pending',
        ]));

        return redirect()->route('payroll.sheets.show', $sheet)
            ->with('success', 'Payroll sheet created. You can now generate salary data.');
    }

    public function show(PayrollSheet $sheet)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);

        $sheet->load(['details.employee', 'comments.employee', 'hrOfficer', 'payrollOfficer', 'directorEmp']);

        $totals = PayrollSheetDetail::where('payroll_sheet_id', $sheet->id)->selectRaw('
            SUM(basic_salary) as basic, SUM(gross_salary) as gross,
            SUM(total_allowance) as allowance, SUM(total_overtime) as overtime,
            SUM(nhif) as nhif, SUM(nhif_employer) as nhif_e,
            SUM(nssf) as nssf, SUM(nssf_employer) as nssf_e,
            SUM(heslb) as heslb, SUM(paye) as paye,
            SUM(total_deduction) as deduction, SUM(net_salary) as net,
            SUM(wcf) as wcf, SUM(sdl) as sdl
        ')->first();

        return view('payroll.sheets.show', compact('sheet', 'totals'));
    }

    public function edit(PayrollSheet $sheet)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);
        abort_unless(in_array($sheet->status, ['pending', 'processing']), 403);

        return view('payroll.sheets.edit', compact('sheet'));
    }

    public function update(Request $request, PayrollSheet $sheet)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);

        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'payroll_from_date' => 'required|date',
            'payroll_to_date'   => 'required|date|after_or_equal:payroll_from_date',
            'number_of_days'    => 'required|integer|min:1|max:31',
            'status'            => 'nullable|in:pending,processing,approved,rejected',
        ]);

        $sheet->update($data);
        return redirect()->route('payroll.sheets.show', $sheet)->with('success', 'Payroll sheet updated.');
    }

    public function destroy(PayrollSheet $sheet)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);
        $sheet->details()->delete();
        $sheet->delete();
        return back()->with('success', 'Payroll sheet deleted.');
    }

    // ── Generate salaries ────────────────────────────────────────────────────

    public function generate(Request $request, PayrollSheet $sheet)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);

        $request->validate(['password' => 'required']);

        if (!Hash::check($request->password, $request->user()->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $service = new PayrollComputationService($this->ctx->id());
        $ok      = $service->generate($sheet);

        if ($ok) {
            return redirect()->route('payroll.sheets.show', $sheet)
                ->with('success', 'Payroll generated successfully.');
        }

        return back()->with('error', 'Payroll generation failed or is already running. Try again shortly.');
    }

    // ── Payslip ──────────────────────────────────────────────────────────────

    public function payslip(PayrollSheet $sheet, PayrollSheetDetail $detail)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);
        abort_unless($detail->payroll_sheet_id === $sheet->id, 404);

        $allowances = AllowanceSheet::where('payroll_sheet_detail_id', $detail->id)->get();
        $overtimes  = OvertimeSheet::where('payroll_sheet_detail_id', $detail->id)->get();
        $loans      = LoanHistory::where('payroll_sheet_detail_id', $detail->id)->get();

        return view('payroll.sheets.payslip', compact('sheet', 'detail', 'allowances', 'overtimes', 'loans'));
    }

    // ── Approval comment ─────────────────────────────────────────────────────

    public function addComment(Request $request, PayrollSheet $sheet)
    {
        abort_unless($sheet->company_id === $this->ctx->id(), 403);

        $request->validate([
            'comment'  => 'required|string|max:1000',
            'remarks'  => 'required|string|max:100',
            'password' => 'required',
        ]);

        if (!Hash::check($request->password, $request->user()->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $employee = $request->user()->employee;

        // State machine
        switch ($request->remarks) {
            case 'submit_to_payroll':
                $sheet->status = 'submitted_payroll';
                $sheet->hr_approved = true;
                $sheet->hr_officer  = $employee?->id;
                break;
            case 'submit_to_director':
                $sheet->status = 'submitted_director';
                $sheet->payroll_approved = true;
                $sheet->payroll_officer  = $employee?->id;
                break;
            case 'submit_to_finance':
                $sheet->status = 'submitted_finance';
                $sheet->director_approved = true;
                $sheet->director = $employee?->id;
                break;
            case 'approved':
                $sheet->status = 'approved';
                break;
            case 'rejected':
                $sheet->status = 'rejected';
                break;
        }

        $sheet->save();

        PayrollComment::create([
            'payroll_sheet_id' => $sheet->id,
            'employee_id'      => $employee?->id,
            'comment'          => $request->comment,
            'remarks'          => $request->remarks,
        ]);

        return back()->with('success', 'Action recorded.');
    }
}
