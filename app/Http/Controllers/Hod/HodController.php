<?php

namespace App\Http\Controllers\Hod;

use App\Http\Controllers\Controller;
use App\Models\AnnualLeaveRoster;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\Roster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HodController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    protected function myEmployee(): ?Employee
    {
        return Employee::where('user_id', Auth::id())
            ->where('company_id', $this->ctx->id())
            ->first();
    }

    protected function myDeptId(): ?int
    {
        $emp = $this->myEmployee();
        if (!$emp) return null;

        $hod = HeadOfDepartment::where('employee_id', $emp->id)->where('is_active', true)->first();
        return $hod?->department_id ?? $emp->department_id;
    }

    public function dashboard()
    {
        $deptId        = $this->myDeptId();
        $pendingLeaves = EmpLeave::where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)->where('status', 'pending')->count();
        $hodApproved   = EmpLeave::where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)->where('status', 'hod_approved')->count();
        $deptEmployees = Employee::where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)->where('employment_status', 'active')->count();
        $onLeave       = EmpLeave::where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)->where('is_active', true)->count();

        $recentRequests = EmpLeave::with(['employee', 'leaveType'])
            ->where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')->limit(5)->get();

        return view('hod.dashboard', compact(
            'pendingLeaves', 'hodApproved', 'deptEmployees', 'onLeave', 'recentRequests'
        ));
    }

    public function leaves(Request $request)
    {
        $deptId = $this->myDeptId();
        $status = $request->get('status', 'pending');

        $leaves = EmpLeave::with(['employee', 'leaveType'])
            ->where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)->withQueryString();

        return view('hod.leaves', compact('leaves', 'status'));
    }

    public function showLeave(EmpLeave $leave)
    {
        $leave->load(['employee.department', 'employee.jobPosition', 'leaveType']);
        return view('hod.leave-show', compact('leave'));
    }

    public function approveLeave(Request $request, EmpLeave $leave)
    {
        $emp = $this->myEmployee();
        $leave->update([
            'hod_is_approved' => true,
            'hod_id'          => $emp?->id,
            'hod_remark'      => $request->remark,
            'status'          => 'hod_approved',
        ]);
        return redirect()->route('hod.leaves')->with('success', 'Leave approved and forwarded to HR.');
    }

    public function rejectLeave(Request $request, EmpLeave $leave)
    {
        $request->validate(['remark' => 'required|string|max:300']);
        $emp = $this->myEmployee();
        $leave->update([
            'hod_is_approved' => false,
            'hod_id'          => $emp?->id,
            'hod_remark'      => $request->remark,
            'status'          => 'rejected',
        ]);
        return redirect()->route('hod.leaves')->with('success', 'Leave request rejected.');
    }

    public function annualLeaves(Request $request)
    {
        $deptId = $this->myDeptId();
        $status = $request->get('status', 'pending');

        $leaves = AnnualLeaveRoster::with(['employee', 'roster'])
            ->where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)->withQueryString();

        return view('hod.annual-leaves', compact('leaves', 'status'));
    }

    public function approveAnnualLeave(Request $request, AnnualLeaveRoster $annualLeave)
    {
        $emp = $this->myEmployee();
        $annualLeave->update([
            'hod_is_approved' => true,
            'hod_id'          => $emp?->id,
            'hod_remark'      => $request->remark,
            'status'          => 'hod_approved',
        ]);
        return redirect()->route('hod.annual-leaves')->with('success', 'Annual leave approved and forwarded to HR.');
    }

    public function rejectAnnualLeave(Request $request, AnnualLeaveRoster $annualLeave)
    {
        $request->validate(['remark' => 'required|string|max:300']);
        $emp = $this->myEmployee();
        $annualLeave->update([
            'hod_is_approved' => false,
            'hod_id'          => $emp?->id,
            'hod_remark'      => $request->remark,
            'status'          => 'rejected',
        ]);
        return redirect()->route('hod.annual-leaves')->with('success', 'Annual leave request rejected.');
    }

    public function employees()
    {
        $deptId    = $this->myDeptId();
        $employees = Employee::with(['jobPosition', 'activeContract'])
            ->where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)
            ->orderBy('fname')->paginate(20);

        return view('hod.employees', compact('employees'));
    }

    public function roster()
    {
        $deptId  = $this->myDeptId();
        $rosters = Roster::with('department')
            ->where('company_id', $this->ctx->id())
            ->where('department_id', $deptId)
            ->orderByDesc('from_date')->paginate(20);

        return view('hod.roster', compact('rosters'));
    }
}
