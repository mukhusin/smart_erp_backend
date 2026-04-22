<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\AbsenceRequest;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AbsenceRequestController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = AbsenceRequest::with('employee')
            ->where('company_id', $this->ctx->id());

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('employee', fn($q) => $q->where('first_name', 'like', "%$s%")
                ->orWhere('last_name', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $absences = $query->orderByDesc('from_date')->paginate(20)->withQueryString();
        return view('leave.absence.index', compact('absences'));
    }

    public function create()
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        return view('leave.absence.create', compact('employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id'    => 'required|exists:employees,id',
            'from_date'      => 'required|date',
            'to_date'        => 'required|date|after_or_equal:from_date',
            'from_time'      => 'nullable|date_format:H:i',
            'to_time'        => 'nullable|date_format:H:i',
            'number_of_days' => 'required|integer|min:1',
            'description'    => 'required|string',
            'address'        => 'nullable|string|max:200',
        ]);

        AbsenceRequest::create(array_merge($data, [
            'company_id'      => $this->ctx->id(),
            'last_updated_by' => auth()->user()->employee?->id,
            'has_per_diem'    => $request->boolean('has_per_diem'),
            'status'          => 'pending',
        ]));

        return redirect()->route('leave.absence.index')->with('success', 'Absence request submitted.');
    }

    public function show(AbsenceRequest $absence)
    {
        $absence->load('employee', 'lastUpdatedBy');
        return view('leave.absence.show', compact('absence'));
    }

    public function edit(AbsenceRequest $absence)
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('first_name')->get();
        return view('leave.absence.edit', compact('absence', 'employees'));
    }

    public function update(Request $request, AbsenceRequest $absence)
    {
        $data = $request->validate([
            'from_date'      => 'required|date',
            'to_date'        => 'required|date|after_or_equal:from_date',
            'from_time'      => 'nullable|date_format:H:i',
            'to_time'        => 'nullable|date_format:H:i',
            'number_of_days' => 'required|integer|min:1',
            'description'    => 'required|string',
            'address'        => 'nullable|string|max:200',
            'status'         => 'nullable|in:pending,approved,rejected',
        ]);

        $absence->update(array_merge($data, [
            'has_per_diem'    => $request->boolean('has_per_diem'),
            'last_updated_by' => auth()->user()->employee?->id,
        ]));

        return redirect()->route('leave.absence.index')->with('success', 'Absence request updated.');
    }

    public function destroy(AbsenceRequest $absence)
    {
        $absence->delete();
        return back()->with('success', 'Absence request deleted.');
    }
}
