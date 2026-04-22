<?php

namespace App\Http\Controllers\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceCategory;
use App\Models\DailyAllowance;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class DailyAllowanceController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = DailyAllowance::with(['employee', 'hodApprover'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $allowances = $query->orderByDesc('start_date')->paginate(20)->withQueryString();
        return view('allowance.daily.index', compact('allowances'));
    }

    public function create()
    {
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        $categories = AllowanceCategory::where('company_id', $this->ctx->id())->where('is_active', true)->where('duration', 'day')->orderBy('category')->get();
        return view('allowance.daily.create', compact('employees', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'items'           => 'required|integer|min:1',
            'amount_per_item' => 'required|numeric|min:0',
            'start_date'      => 'required|date',
            'end_date'        => 'required|date|after_or_equal:start_date',
            'description'     => 'nullable|string',
        ]);

        $amount   = $data['items'] * $data['amount_per_item'];
        $category = $request->input('category', 'Daily');

        DailyAllowance::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'category'   => $category,
            'duration'   => 'day',
            'amount'     => $amount,
            'status'     => 'pending',
            'is_active'  => false,
        ]));

        return redirect()->route('allowance.daily.index')->with('success', 'Daily allowance submitted.');
    }

    public function show(DailyAllowance $daily)
    {
        $daily->load(['employee', 'hodApprover', 'hrApprover']);
        return view('allowance.daily.show', compact('daily'));
    }

    public function edit(DailyAllowance $daily)
    {
        $employees  = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        $categories = AllowanceCategory::where('company_id', $this->ctx->id())->where('is_active', true)->where('duration', 'day')->orderBy('category')->get();
        return view('allowance.daily.edit', compact('daily', 'employees', 'categories'));
    }

    public function update(Request $request, DailyAllowance $daily)
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
        $daily->update($data);

        return redirect()->route('allowance.daily.index')->with('success', 'Daily allowance updated.');
    }

    public function destroy(DailyAllowance $daily)
    {
        $daily->delete();
        return back()->with('success', 'Daily allowance deleted.');
    }
}
