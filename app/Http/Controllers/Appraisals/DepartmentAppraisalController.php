<?php
namespace App\Http\Controllers\Appraisals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentAppraisal;
use App\Models\JobPosition;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class DepartmentAppraisalController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $appraisals = DepartmentAppraisal::where('company_id', $this->ctx->id())
            ->with('department', 'jobPosition')
            ->withCount('kpis')
            ->latest()->get();
        return view('appraisals.templates.index', compact('appraisals'));
    }

    public function create()
    {
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $positions   = JobPosition::where('company_id', $this->ctx->id())->orderBy('title')->get();
        return view('appraisals.templates.create', compact('departments', 'positions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id'  => 'nullable|exists:departments,id',
            'job_position_id'=> 'nullable|exists:job_positions,id',
            'appraisal_title'=> 'required|string|max:200',
            'description'    => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        $data['created_by'] = auth()->id();
        $data['is_active']  = true;
        DepartmentAppraisal::create($data);
        return redirect()->route('appraisals.templates.index')->with('success', 'Appraisal template created.');
    }

    public function show(DepartmentAppraisal $template)
    {
        $template->load('department', 'jobPosition', 'kpis');
        return view('appraisals.templates.show', compact('template'));
    }

    public function edit(DepartmentAppraisal $template)
    {
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $positions   = JobPosition::where('company_id', $this->ctx->id())->orderBy('title')->get();
        return view('appraisals.templates.edit', compact('template', 'departments', 'positions'));
    }

    public function update(Request $request, DepartmentAppraisal $template)
    {
        $data = $request->validate([
            'department_id'  => 'nullable|exists:departments,id',
            'job_position_id'=> 'nullable|exists:job_positions,id',
            'appraisal_title'=> 'required|string|max:200',
            'description'    => 'nullable|string',
            'is_active'      => 'boolean',
        ]);
        $template->update($data);
        return redirect()->route('appraisals.templates.show', $template)->with('success', 'Template updated.');
    }

    public function destroy(DepartmentAppraisal $template)
    {
        $template->delete();
        return back()->with('success', 'Appraisal template deleted.');
    }

    public function storeKpi(Request $request, DepartmentAppraisal $template)
    {
        $data = $request->validate([
            'kpi_title'   => 'required|string|max:200',
            'description' => 'nullable|string',
            'weight'      => 'required|numeric|min:0|max:100',
        ]);
        $template->kpis()->create($data);
        return back()->with('success', 'KPI added.');
    }

    public function destroyKpi(DepartmentAppraisal $template, \App\Models\AppraisalKpi $kpi)
    {
        $kpi->delete();
        return back()->with('success', 'KPI removed.');
    }
}
