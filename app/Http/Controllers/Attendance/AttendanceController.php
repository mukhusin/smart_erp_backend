<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceTransa;
use App\Models\Department;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = AttendanceTransa::with(['employee', 'employee.department'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('date_from')) {
            $query->where('transaction_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('transaction_date', '<=', $request->date_to);
        }
        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $request->department_id));
        }

        $transactions = $query->orderByDesc('transaction_date')->paginate(30)->withQueryString();
        $employees    = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        $departments  = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();

        return view('attendance.index', compact('transactions', 'employees', 'departments'));
    }
}
