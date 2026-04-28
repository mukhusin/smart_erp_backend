<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryAdjustment;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class SalaryAdjustmentController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = SalaryAdjustment::with(['employee'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $adjustments = $query->orderByDesc('effective_date')->paginate(20)->withQueryString();
        $employees   = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('payroll.salary-adjustments.index', compact('adjustments', 'employees'));
    }

    public function create()
    {
        $employees = Employee::where('company_id', $this->ctx->id())
            ->with('activeContract')->orderBy('fname')->get();
        return view('payroll.salary-adjustments.create', compact('employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'contract_id'     => 'nullable|exists:employee_contracts,id',
            'adjustment_type' => 'required|in:increase,decrease',
            'current_salary'  => 'required|numeric|min:0',
            'percentage'      => 'required|numeric|min:0|max:100',
            'amount'          => 'required|numeric|min:0',
            'new_salary'      => 'required|numeric|min:0',
            'reason'          => 'required|string|max:200',
            'effective_date'  => 'required|date',
            'description'     => 'nullable|string',
        ]);

        SalaryAdjustment::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'created_by' => auth()->user()->employee?->id,
            'status'     => 'pending',
            'is_active'  => true,
        ]));

        return redirect()->route('payroll.salary-adjustments.index')->with('success', 'Salary adjustment submitted.');
    }

    public function show(SalaryAdjustment $salaryAdjustment)
    {
        $salaryAdjustment->load(['employee', 'contract', 'hrApprover', 'financeApprover', 'directorApprover']);
        return view('payroll.salary-adjustments.show', compact('salaryAdjustment'));
    }

    public function edit(SalaryAdjustment $salaryAdjustment)
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('payroll.salary-adjustments.edit', compact('salaryAdjustment', 'employees'));
    }

    public function update(Request $request, SalaryAdjustment $salaryAdjustment)
    {
        $data = $request->validate([
            'reason'         => 'required|string|max:200',
            'effective_date' => 'required|date',
            'description'    => 'nullable|string',
            'status'         => 'nullable|in:pending,approved,rejected',
        ]);

        $data['updated_by'] = auth()->user()->employee?->id;
        $salaryAdjustment->update($data);

        return redirect()->route('payroll.salary-adjustments.index')->with('success', 'Salary adjustment updated.');
    }

    public function destroy(SalaryAdjustment $salaryAdjustment)
    {
        $salaryAdjustment->delete();
        return back()->with('success', 'Salary adjustment deleted.');
    }
}
