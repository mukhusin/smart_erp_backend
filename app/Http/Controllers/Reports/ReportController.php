<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\AttendanceTransa;
use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveType;
use App\Models\NatureOfContract;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        return view('reports.index');
    }

    public function employees(Request $request)
    {
        $companyId   = $this->ctx->id();
        $departments = Department::where('company_id', $companyId)->orderBy('name')->get();

        $query = Employee::with(['department', 'jobPosition', 'activeContract.natureOfContract'])
            ->where('company_id', $companyId);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }
        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->employment_status);
        }
        if ($request->filled('emp_type')) {
            $query->where('emp_type', $request->emp_type);
        }

        $employees = $query->orderBy('fname')->get();

        return view('reports.employees', compact('employees', 'departments'));
    }

    public function leaves(Request $request)
    {
        $companyId   = $this->ctx->id();
        $departments = Department::where('company_id', $companyId)->orderBy('name')->get();
        $leaveTypes  = LeaveType::where('company_id', $companyId)->orderBy('name')->get();

        $query = EmpLeave::with(['employee.department', 'employee.jobPosition', 'leaveType'])
            ->where('company_id', $companyId);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->leave_type_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('requested_start_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('requested_end_date', '<=', $request->to_date);
        }

        $leaves = $query->orderByDesc('created_at')->get();

        $summary = $leaves->groupBy(fn($l) => $l->leaveType?->name ?? 'Unknown')
            ->map(fn($group) => ['count' => $group->count(), 'days' => $group->sum('days_requested')]);

        return view('reports.leaves', compact('leaves', 'departments', 'leaveTypes', 'summary'));
    }

    public function contracts(Request $request)
    {
        $companyId     = $this->ctx->id();
        $departments   = Department::where('company_id', $companyId)->orderBy('name')->get();
        $contractTypes = NatureOfContract::where('company_id', $companyId)->orderBy('contract_name')->get();
        $expiresInDays = (int) $request->get('expires_in', 60);
        $today         = Carbon::today();
        $threshold     = $today->copy()->addDays($expiresInDays);

        $query = EmployeeContract::with(['employee.department', 'employee.jobPosition', 'natureOfContract'])
            ->whereHas('employee', fn($q) => $q->where('company_id', $companyId))
            ->where('is_active', true);

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $request->department_id));
        }
        if ($request->filled('nature_of_contract_id')) {
            $query->where('nature_of_contract_id', $request->nature_of_contract_id);
        }

        $filter = $request->get('filter', 'expiring');
        if ($filter === 'expiring') {
            $query->whereNotNull('end_date')
                  ->whereDate('end_date', '>=', $today)
                  ->whereDate('end_date', '<=', $threshold);
        } elseif ($filter === 'expired') {
            $query->whereNotNull('end_date')->whereDate('end_date', '<', $today);
        }
        // 'all' shows every active contract

        $contracts = $query->orderBy('end_date')->get();

        return view('reports.contracts', compact('contracts', 'departments', 'contractTypes', 'expiresInDays', 'filter', 'today'));
    }

    public function attendance(Request $request)
    {
        $companyId   = $this->ctx->id();
        $departments = Department::where('company_id', $companyId)->orderBy('name')->get();

        $fromDate = $request->filled('from_date') ? Carbon::parse($request->from_date) : Carbon::now()->startOfMonth();
        $toDate   = $request->filled('to_date')   ? Carbon::parse($request->to_date)   : Carbon::now();

        $query = AttendanceTransa::with(['employee.department', 'employee.jobPosition'])
            ->where('company_id', $companyId)
            ->whereBetween('transaction_date', [$fromDate->toDateString(), $toDate->toDateString()]);

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $request->department_id));
        }

        $records = $query->get();

        $grouped = $records->groupBy('employee_id')->map(function ($rows) {
            $emp = $rows->first()->employee;
            return [
                'employee'   => $emp,
                'total'      => $rows->count(),
                'present'    => $rows->where('pass_day', true)->count(),
                'absent'     => $rows->where('pass_day', false)->count(),
                'on_leave'   => 0, // not tracked per-row in ERP attendance
                'late'       => $rows->filter(fn($r) => ($r->punch_in_late_minutes ?? 0) > 0)->count(),
            ];
        })->sortBy(fn($d) => $d['employee']?->fname ?? '');

        return view('reports.attendance', compact('grouped', 'departments', 'fromDate', 'toDate'));
    }
}
