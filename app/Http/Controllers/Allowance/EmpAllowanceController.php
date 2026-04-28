<?php

namespace App\Http\Controllers\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceCategory;
use App\Models\EmpAllowance;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class EmpAllowanceController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = EmpAllowance::with(['employee', 'category'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $allowances = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('allowance.requests.index', compact('allowances', 'employees'));
    }

    public function create()
    {
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        $categories = AllowanceCategory::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('category')->get();
        return view('allowance.requests.create', compact('employees', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'          => 'required|exists:employees,id',
            'allowance_category_id'=> 'required|exists:allowance_categories,id',
            'items'                => 'required|integer|min:1',
            'amount_per_item'      => 'required|numeric|min:0',
            'start_date'           => 'required|date',
            'end_date'             => 'required|date|after_or_equal:start_date',
            'description'          => 'nullable|string',
        ]);

        $cat = AllowanceCategory::findOrFail($data['allowance_category_id']);
        $amount = $data['items'] * $data['amount_per_item'];

        EmpAllowance::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'category'   => $cat->category,
            'duration'   => $cat->duration,
            'amount'     => $amount,
            'status'     => 'pending',
            'is_active'  => false,
        ]));

        return redirect()->route('allowance.requests.index')->with('success', 'Allowance request submitted.');
    }

    public function show(EmpAllowance $request)
    {
        $request->load(['employee', 'category', 'hodApprover', 'hrApprover']);
        return view('allowance.requests.show', ['allowance' => $request]);
    }

    public function edit(EmpAllowance $request)
    {
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        $categories = AllowanceCategory::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('category')->get();
        return view('allowance.requests.edit', ['allowance' => $request, 'employees' => $employees, 'categories' => $categories]);
    }

    public function update(Request $request, EmpAllowance $request_model)
    {
        $data = $request->validate([
            'items'           => 'required|integer|min:1',
            'amount_per_item' => 'required|numeric|min:0',
            'start_date'      => 'required|date',
            'end_date'        => 'required|date|after_or_equal:start_date',
            'description'     => 'nullable|string',
            'status'          => 'nullable|in:pending,approved,rejected',
        ]);

        $data['amount'] = $data['items'] * $data['amount_per_item'];
        $request_model->update($data);

        return redirect()->route('allowance.requests.index')->with('success', 'Allowance updated.');
    }

    public function destroy(EmpAllowance $request)
    {
        $request->delete();
        return back()->with('success', 'Allowance deleted.');
    }
}
