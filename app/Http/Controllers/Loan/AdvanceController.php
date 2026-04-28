<?php

namespace App\Http\Controllers\Loan;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeMonthlyAdvance;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AdvanceController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = EmployeeMonthlyAdvance::with('employee')
            ->where('company_id', $this->ctx->id());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('month')) {
            $query->where('month', $request->month);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        $advances  = $query->orderByDesc('date_advanced')->paginate(20)->withQueryString();
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('loans.advances.index', compact('advances', 'employees'));
    }

    public function create()
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('loans.advances.create', compact('employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'   => 'required|exists:employees,id',
            'amount'        => 'required|numeric|min:0',
            'date_advanced' => 'required|date',
            'month'         => 'nullable|string',
            'year'          => 'nullable|string|max:4',
        ]);

        EmployeeMonthlyAdvance::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'created_by' => auth()->user()->employee?->id,
            'status'     => 'pending',
            'is_active'  => true,
        ]));

        return redirect()->route('loans.advances.index')->with('success', 'Monthly advance created.');
    }

    public function edit(EmployeeMonthlyAdvance $advance)
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('loans.advances.edit', compact('advance', 'employees'));
    }

    public function update(Request $request, EmployeeMonthlyAdvance $advance)
    {
        $data = $request->validate([
            'amount'  => 'required|numeric|min:0',
            'status'  => 'nullable|in:pending,deducted,cancelled',
        ]);

        $advance->update($data);
        return redirect()->route('loans.advances.index')->with('success', 'Advance updated.');
    }

    public function destroy(EmployeeMonthlyAdvance $advance)
    {
        $advance->delete();
        return back()->with('success', 'Advance deleted.');
    }
}
