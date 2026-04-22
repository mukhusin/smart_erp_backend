<?php
namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $departments = Department::with(['parent','headOfDepartment.employee'])
            ->where('company_id', $this->ctx->id())
            ->whereNull('parent_id')
            ->withCount('sections','employees')
            ->latest()->get();
        return view('hr.departments.index', compact('departments'));
    }

    public function create()
    {
        $parents  = Department::where('company_id',$this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $branches = CompanyBranch::where('company_id',$this->ctx->id())->where('is_active',true)->orderBy('name')->get();
        return view('hr.departments.create', compact('parents','branches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'short_name'        => 'nullable|string|max:30',
            'parent_id'         => 'nullable|exists:departments,id',
            'company_branch_id' => 'nullable|exists:company_branches,id',
            'description'       => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        Department::create($data);
        return redirect()->route('hr.departments.index')->with('success','Department created successfully.');
    }

    public function show(Department $department)
    {
        $department->load(['sections.employees','headOfDepartment.employee','employees.jobPosition']);
        return view('hr.departments.show', compact('department'));
    }

    public function edit(Department $department)
    {
        $parents  = Department::where('company_id',$this->ctx->id())->whereNull('parent_id')->where('id','!=',$department->id)->orderBy('name')->get();
        $branches = CompanyBranch::where('company_id',$this->ctx->id())->where('is_active',true)->orderBy('name')->get();
        return view('hr.departments.edit', compact('department','parents','branches'));
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'short_name'        => 'nullable|string|max:30',
            'parent_id'         => 'nullable|exists:departments,id',
            'company_branch_id' => 'nullable|exists:company_branches,id',
            'description'       => 'nullable|string',
        ]);
        $department->update($data);
        return redirect()->route('hr.departments.index')->with('success','Department updated successfully.');
    }

    public function destroy(Department $department)
    {
        if ($department->employees()->count()) {
            return back()->with('error','Cannot delete a department with active employees.');
        }
        $department->delete();
        return back()->with('success','Department deleted.');
    }
}
