<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Overtime;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = Overtime::with(['employee', 'department'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('date_from')) {
            $query->where('date_of_task_done', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date_of_task_done', '<=', $request->date_to);
        }

        $overtimes   = $query->orderByDesc('date_of_task_done')->paginate(25)->withQueryString();
        $employees   = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();

        return view('overtime.index', compact('overtimes', 'employees', 'departments'));
    }
}
