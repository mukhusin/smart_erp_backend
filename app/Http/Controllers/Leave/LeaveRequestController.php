<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = EmpLeave::with(['employee', 'leaveType'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('employee', fn($q) => $q->where('first_name', 'like', "%$s%")
                ->orWhere('last_name', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $leaves = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        return view('leave.requests.index', compact('leaves'));
    }

    public function create()
    {
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        $leaveTypes = LeaveType::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('leave.requests.create', compact('employees', 'leaveTypes'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'    => 'required|exists:employees,id',
            'leave_type_id'  => 'required|exists:leave_types,id',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date|after_or_equal:start_date',
            'days_requested' => 'required|integer|min:1',
            'leave_year'     => 'required|string',
            'description'    => 'nullable|string',
            'leave_phone'    => 'nullable|string|max:20',
            'leave_address'  => 'nullable|string|max:200',
        ]);

        EmpLeave::create(array_merge($data, [
            'company_id'        => $this->ctx->id(),
            'requested_start_date' => $data['start_date'],
            'requested_end_date'   => $data['end_date'],
            'days'              => $data['days_requested'],
            'status'            => 'pending',
        ]));

        return redirect()->route('leave.requests.index')->with('success', 'Leave request submitted.');
    }

    public function show(EmpLeave $request)
    {
        $request->load(['employee', 'leaveType', 'hod', 'hrOfficer']);
        return view('leave.requests.show', ['leave' => $request]);
    }

    public function edit(EmpLeave $request)
    {
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        $leaveTypes = LeaveType::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('leave.requests.edit', ['leave' => $request, 'employees' => $employees, 'leaveTypes' => $leaveTypes]);
    }

    public function update(Request $request, EmpLeave $request_model)
    {
        $data = $request->validate([
            'leave_type_id'  => 'required|exists:leave_types,id',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date|after_or_equal:start_date',
            'days_requested' => 'required|integer|min:1',
            'leave_year'     => 'required|string',
            'description'    => 'nullable|string',
        ]);

        $request_model->update($data);
        return redirect()->route('leave.requests.index')->with('success', 'Leave request updated.');
    }

    public function destroy(EmpLeave $request)
    {
        $request->delete();
        return back()->with('success', 'Leave request deleted.');
    }

    public function approve(Request $request, EmpLeave $request_model)
    {
        $hrEmp = Employee::where('user_id', Auth::id())
            ->where('company_id', $this->ctx->id())->first();

        $request_model->update([
            'hr_office_is_approved' => true,
            'hr_id'                 => $hrEmp?->id,
            'hr_office_remark'      => $request->remark,
            'status'                => 'approved',
            'is_active'             => true,
            'start_date'            => $request_model->requested_start_date,
            'end_date'              => $request_model->requested_end_date,
        ]);

        return back()->with('success', 'Leave request approved.');
    }

    public function reject(Request $request, EmpLeave $request_model)
    {
        $request->validate(['remark' => 'required|string|max:300']);
        $hrEmp = Employee::where('user_id', Auth::id())
            ->where('company_id', $this->ctx->id())->first();

        $request_model->update([
            'hr_office_is_approved' => false,
            'hr_id'                 => $hrEmp?->id,
            'hr_office_remark'      => $request->remark,
            'status'                => 'rejected',
        ]);

        return back()->with('success', 'Leave request rejected.');
    }
}
