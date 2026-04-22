<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollSheet;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class PayrollSheetController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = PayrollSheet::where('company_id', $this->ctx->id());

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $sheets = $query->orderByDesc('year')->orderByDesc('month')->paginate(20)->withQueryString();
        return view('payroll.sheets.index', compact('sheets'));
    }

    public function create()
    {
        return view('payroll.sheets.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'month'             => 'required|string',
            'year'              => 'required|string|max:4',
            'payroll_from_date' => 'required|date',
            'payroll_to_date'   => 'required|date|after_or_equal:payroll_from_date',
            'number_of_days'    => 'required|integer|min:1|max:31',
        ]);

        PayrollSheet::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'status'     => 'pending',
        ]));

        return redirect()->route('payroll.sheets.index')->with('success', 'Payroll sheet created.');
    }

    public function show(PayrollSheet $sheet)
    {
        $sheet->load(['details.employee', 'hrOfficer', 'payrollOfficer', 'directorEmp']);
        return view('payroll.sheets.show', compact('sheet'));
    }

    public function edit(PayrollSheet $sheet)
    {
        return view('payroll.sheets.edit', compact('sheet'));
    }

    public function update(Request $request, PayrollSheet $sheet)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'payroll_from_date' => 'required|date',
            'payroll_to_date'   => 'required|date|after_or_equal:payroll_from_date',
            'number_of_days'    => 'required|integer|min:1|max:31',
            'status'            => 'nullable|in:pending,processing,approved,rejected',
        ]);

        $sheet->update($data);
        return redirect()->route('payroll.sheets.index')->with('success', 'Payroll sheet updated.');
    }

    public function destroy(PayrollSheet $sheet)
    {
        $sheet->delete();
        return back()->with('success', 'Payroll sheet deleted.');
    }
}
