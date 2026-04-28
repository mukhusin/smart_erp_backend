<?php

namespace App\Http\Controllers\Roster;

use App\Http\Controllers\Controller;
use App\Models\AnnualLeaveRoster;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Roster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnualLeaveRosterController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = AnnualLeaveRoster::with(['employee.department', 'roster'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('leave_year')) {
            $query->where('leave_year', $request->leave_year);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $annualLeaves = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $departments  = \App\Models\Department::where('company_id', $this->ctx->id())
            ->whereNull('parent_id')->orderBy('name')->get();

        return view('roster.annual-leave.index', compact('annualLeaves', 'departments'));
    }

    public function approve(Request $request, AnnualLeaveRoster $annualLeave)
    {
        $hrEmp = Employee::where('user_id', Auth::id())
            ->where('company_id', $this->ctx->id())->first();

        $annualLeave->update([
            'hr_office_is_approved' => true,
            'hr_id'                 => $hrEmp?->id,
            'hr_office_remark'      => $request->remark,
            'status'                => 'approved',
            'is_active'             => true,
        ]);

        return back()->with('success', 'Annual leave approved.');
    }

    public function reject(Request $request, AnnualLeaveRoster $annualLeave)
    {
        $request->validate(['remark' => 'required|string|max:300']);
        $hrEmp = Employee::where('user_id', Auth::id())
            ->where('company_id', $this->ctx->id())->first();

        $annualLeave->update([
            'hr_office_is_approved' => false,
            'hr_id'                 => $hrEmp?->id,
            'hr_office_remark'      => $request->remark,
            'status'                => 'rejected',
        ]);

        return back()->with('success', 'Annual leave rejected.');
    }

    public function create()
    {
        $rosters   = Roster::where('company_id', $this->ctx->id())->orderByDesc('created_at')->get();
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('roster.annual-leave.create', compact('rosters', 'employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'roster_id'    => 'required|exists:rosters,id',
            'employee_id'  => 'required|exists:employees,id',
            'start_date'   => 'required|date',
            'end_date'     => 'required|date|after_or_equal:start_date',
            'days'         => 'required|integer|min:1',
            'leave_year'   => 'nullable|string|max:4',
            'description'  => 'nullable|string',
            'leave_phone'  => 'nullable|string|max:20',
            'leave_address'=> 'nullable|string|max:200',
        ]);

        AnnualLeaveRoster::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'status'     => 'not_requested',
            'is_active'  => false,
        ]));

        return redirect()->route('roster.annual-leave.index')->with('success', 'Annual leave roster entry created.');
    }

    public function show(AnnualLeaveRoster $annualLeave)
    {
        $annualLeave->load(['employee', 'roster', 'hod', 'hr']);
        return view('roster.annual-leave.show', compact('annualLeave'));
    }

    public function edit(AnnualLeaveRoster $annualLeave)
    {
        $rosters   = Roster::where('company_id', $this->ctx->id())->orderByDesc('created_at')->get();
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('roster.annual-leave.edit', compact('annualLeave', 'rosters', 'employees'));
    }

    public function update(Request $request, AnnualLeaveRoster $annualLeave)
    {
        $data = $request->validate([
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'days'        => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $annualLeave->update($data);
        return redirect()->route('roster.annual-leave.index')->with('success', 'Annual leave roster updated.');
    }

    public function destroy(AnnualLeaveRoster $annualLeave)
    {
        $annualLeave->delete();
        return back()->with('success', 'Annual leave roster entry deleted.');
    }
}
