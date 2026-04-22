<?php

namespace App\Http\Controllers\Loan;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LoanProvider;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = EmployeeLoan::with(['employee', 'loanProvider'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $loans     = $query->orderByDesc('date_of_loan')->paginate(20)->withQueryString();
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        return view('loans.index', compact('loans', 'employees'));
    }

    public function create()
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        $providers = LoanProvider::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('loans.create', compact('employees', 'providers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'      => 'required|exists:employees,id',
            'loan_provider_id' => 'nullable|exists:loan_providers,id',
            'date_of_loan'     => 'required|date',
            'amount'           => 'required|numeric|min:0',
            'instalment'       => 'required|numeric|min:0',
            'end_date'         => 'nullable|date',
        ]);

        EmployeeLoan::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'remain'     => $data['amount'],
            'returned'   => 0,
            'is_active'  => true,
            'status'     => 'enabled',
        ]));

        return redirect()->route('loans.index')->with('success', 'Loan created.');
    }

    public function show(EmployeeLoan $loan)
    {
        $loan->load(['employee', 'loanProvider', 'histories']);
        return view('loans.show', compact('loan'));
    }

    public function edit(EmployeeLoan $loan)
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        $providers = LoanProvider::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('loans.edit', compact('loan', 'employees', 'providers'));
    }

    public function update(Request $request, EmployeeLoan $loan)
    {
        $data = $request->validate([
            'instalment' => 'required|numeric|min:0',
            'end_date'   => 'nullable|date',
            'status'     => 'nullable|in:enabled,completed,suspended',
        ]);

        $data['updated_by'] = auth()->user()->employee?->id;
        $loan->update($data);

        return redirect()->route('loans.index')->with('success', 'Loan updated.');
    }

    public function destroy(EmployeeLoan $loan)
    {
        $loan->delete();
        return back()->with('success', 'Loan deleted.');
    }
}
