<?php

namespace App\Http\Controllers\Appraisals;

use App\Http\Controllers\Controller;
use App\Models\AppraisalKpiScore;
use App\Models\DepartmentAppraisal;
use App\Models\Employee;
use App\Models\EmployeeAppraisal;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class EmployeeAppraisalController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = EmployeeAppraisal::with(['employee', 'departmentAppraisal'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('period'))  $query->where('period', $request->period);
        if ($request->filled('status'))  $query->where('status', $request->status);
        if ($request->filled('template')) $query->where('department_appraisal_id', $request->template);

        $appraisals = $query->orderByDesc('id')->paginate(30)->withQueryString();
        $templates  = DepartmentAppraisal::where('company_id', $this->ctx->id())->where('is_active', true)->get();

        return view('appraisals.employee.index', compact('appraisals', 'templates'));
    }

    public function create()
    {
        $templates = DepartmentAppraisal::with('kpis')
            ->where('company_id', $this->ctx->id())
            ->where('is_active', true)
            ->get();

        $employees = Employee::where('company_id', $this->ctx->id())
            ->whereHas('activeContract')
            ->orderBy('fname')
            ->get();

        return view('appraisals.employee.create', compact('templates', 'employees'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'employee_id'             => 'required|exists:employees,id',
            'department_appraisal_id' => 'required|exists:department_appraisals,id',
            'period'                  => 'required|string|max:50',
        ]);

        // Prevent duplicate for same employee + template + period
        $exists = EmployeeAppraisal::where([
            'employee_id'             => $request->employee_id,
            'department_appraisal_id' => $request->department_appraisal_id,
            'period'                  => $request->period,
        ])->exists();

        if ($exists) {
            return back()->withErrors(['period' => 'An appraisal already exists for this employee, template, and period.'])->withInput();
        }

        $appraisal = EmployeeAppraisal::create([
            'company_id'              => $this->ctx->id(),
            'employee_id'             => $request->employee_id,
            'department_appraisal_id' => $request->department_appraisal_id,
            'period'                  => $request->period,
            'status'                  => 'draft',
        ]);

        // Pre-create score rows for each KPI
        $template = DepartmentAppraisal::with('kpis')->find($request->department_appraisal_id);
        foreach ($template->kpis as $kpi) {
            AppraisalKpiScore::create([
                'employee_appraisal_id' => $appraisal->id,
                'appraisal_kpi_id'      => $kpi->id,
            ]);
        }

        return redirect()->route('appraisals.employee.show', $appraisal)
            ->with('success', 'Appraisal instance created. Employee can now complete self-assessment.');
    }

    public function show(EmployeeAppraisal $employeeAppraisal)
    {
        abort_unless($employeeAppraisal->company_id === $this->ctx->id(), 403);
        $employeeAppraisal->load(['employee', 'departmentAppraisal.kpis', 'scores.kpi', 'reviewer']);
        $ratingScales = \App\Models\AppraisalRatingScale::where('company_id', $this->ctx->id())->get();
        return view('appraisals.employee.show', compact('employeeAppraisal', 'ratingScales'));
    }

    public function saveSelfAssessment(Request $request, EmployeeAppraisal $employeeAppraisal)
    {
        abort_unless($employeeAppraisal->company_id === $this->ctx->id(), 403);
        abort_unless(in_array($employeeAppraisal->status, ['draft', 'self_assessed']), 403);

        $request->validate([
            'employee_comment' => 'nullable|string|max:2000',
            'scores'           => 'required|array',
            'scores.*.id'      => 'required|exists:appraisal_kpi_scores,id',
            'scores.*.self_score'   => 'nullable|numeric|min:0|max:100',
            'scores.*.self_comment' => 'nullable|string|max:500',
        ]);

        foreach ($request->scores as $row) {
            AppraisalKpiScore::where('id', $row['id'])->update([
                'self_score'   => $row['self_score']   ?? null,
                'self_comment' => $row['self_comment'] ?? null,
            ]);
        }

        $employeeAppraisal->update([
            'employee_comment' => $request->employee_comment,
            'status'           => 'self_assessed',
        ]);

        return back()->with('success', 'Self-assessment saved.');
    }

    public function saveAgreedScores(Request $request, EmployeeAppraisal $employeeAppraisal)
    {
        abort_unless($employeeAppraisal->company_id === $this->ctx->id(), 403);

        $request->validate([
            'hod_comment' => 'nullable|string|max:2000',
            'scores'      => 'required|array',
            'scores.*.id' => 'required|exists:appraisal_kpi_scores,id',
            'scores.*.agreed_score'   => 'nullable|numeric|min:0|max:100',
            'scores.*.agreed_comment' => 'nullable|string|max:500',
        ]);

        foreach ($request->scores as $row) {
            AppraisalKpiScore::where('id', $row['id'])->update([
                'agreed_score'   => $row['agreed_score']   ?? null,
                'agreed_comment' => $row['agreed_comment'] ?? null,
            ]);
        }

        $employeeAppraisal->refresh()->load('scores.kpi');
        $total = $employeeAppraisal->scores->sum(fn($s) =>
            ($s->agreed_score ?? 0) * (($s->kpi->weight ?? 0) / 100)
        );

        $employeeAppraisal->update([
            'hod_comment' => $request->hod_comment,
            'total_score' => round($total, 2),
            'status'      => 'hod_reviewed',
            'reviewed_by' => $request->user()->employee?->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Agreed scores saved. Total score: ' . round($total, 2) . '%');
    }

    public function complete(EmployeeAppraisal $employeeAppraisal)
    {
        abort_unless($employeeAppraisal->company_id === $this->ctx->id(), 403);
        $employeeAppraisal->update(['status' => 'completed']);
        return back()->with('success', 'Appraisal marked as completed.');
    }

    public function destroy(EmployeeAppraisal $employeeAppraisal)
    {
        abort_unless($employeeAppraisal->company_id === $this->ctx->id(), 403);
        abort_unless($employeeAppraisal->status === 'draft', 403);
        $employeeAppraisal->scores()->delete();
        $employeeAppraisal->delete();
        return redirect()->route('appraisals.employee.index')->with('success', 'Appraisal deleted.');
    }
}
