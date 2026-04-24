<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\AnnualLeaveRoster;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PayrollSheetDetail;
use App\Models\Roster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SelfServiceController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    protected function myEmployee(): ?Employee
    {
        return Employee::where('user_id', Auth::id())
            ->where('company_id', $this->ctx->id())
            ->first();
    }

    public function dashboard()
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return view('self-service.no-profile');
        }

        $employee->load(['department', 'jobPosition', 'activeContract.natureOfContract']);

        $pendingLeaves = EmpLeave::where('employee_id', $employee->id)->where('status', 'pending')->count();
        $onLeaveNow    = EmpLeave::where('employee_id', $employee->id)->where('is_active', true)->count();
        $totalLeaves   = EmpLeave::where('employee_id', $employee->id)->count();
        $latestPayslip = PayrollSheetDetail::with('payrollSheet')
            ->where('employee_id', $employee->id)->latest()->first();

        $recentLeaves = EmpLeave::with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')->limit(5)->get();

        return view('self-service.dashboard', compact(
            'employee', 'pendingLeaves', 'onLeaveNow', 'totalLeaves', 'latestPayslip', 'recentLeaves'
        ));
    }

    public function profile()
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return view('self-service.no-profile');
        }
        $employee->load([
            'department', 'section', 'jobPosition', 'branch',
            'activeContract.natureOfContract',
            'qualifications', 'experiences', 'bankAccounts', 'dependants',
        ]);
        return view('self-service.profile', compact('employee'));
    }

    public function myLeaves(Request $request)
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return view('self-service.no-profile');
        }

        $query = EmpLeave::with('leaveType')
            ->where('employee_id', $employee->id);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $leaves = $query->orderByDesc('created_at')->paginate(12)->withQueryString();

        $annualLeaves = AnnualLeaveRoster::with('roster')
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')->paginate(10);

        return view('self-service.my-leaves', compact('employee', 'leaves', 'annualLeaves'));
    }

    public function requestLeave()
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return view('self-service.no-profile');
        }

        $leaveTypes = LeaveType::where('company_id', $this->ctx->id())
            ->where('is_active', true)
            ->where(function ($q) use ($employee) {
                $q->whereNull('gender')->orWhere('gender', '')->orWhere('gender', $employee->gender);
            })
            ->orderBy('name')->get();

        return view('self-service.leave-request', compact('employee', 'leaveTypes'));
    }

    public function submitLeave(Request $request)
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return back()->with('error', 'No employee profile is linked to your account.');
        }

        $data = $request->validate([
            'leave_type_id'  => 'required|exists:leave_types,id',
            'start_date'     => 'required|date|after_or_equal:today',
            'end_date'       => 'required|date|after_or_equal:start_date',
            'days_requested' => 'required|integer|min:1',
            'description'    => 'nullable|string|max:500',
            'leave_phone'    => 'nullable|string|max:20',
            'leave_address'  => 'nullable|string|max:200',
        ]);

        EmpLeave::create(array_merge($data, [
            'company_id'           => $this->ctx->id(),
            'employee_id'          => $employee->id,
            'department_id'        => $employee->department_id,
            'leave_year'           => now()->year,
            'days'                 => $data['days_requested'],
            'requested_start_date' => $data['start_date'],
            'requested_end_date'   => $data['end_date'],
            'status'               => 'pending',
        ]));

        return redirect()->route('self-service.my-leaves')
            ->with('success', 'Leave request submitted. Awaiting HOD approval.');
    }

    public function dutyRoster()
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return view('self-service.no-profile');
        }

        $rosters = Roster::with('department')
            ->where('company_id', $this->ctx->id())
            ->where('type', 'duty')
            ->where(function ($q) use ($employee) {
                $q->where('department_id', $employee->department_id);
                if ($employee->section_id) {
                    $q->orWhere('section_id', $employee->section_id);
                }
            })
            ->where('is_active', true)
            ->orderByDesc('from_date')
            ->paginate(10);

        return view('self-service.duty-roster', compact('employee', 'rosters'));
    }

    public function payslip()
    {
        $employee = $this->myEmployee();
        if (!$employee) {
            return view('self-service.no-profile');
        }

        $payslips = PayrollSheetDetail::with('payrollSheet')
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('self-service.payslip', compact('employee', 'payslips'));
    }
}
