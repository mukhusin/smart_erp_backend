<?php

namespace App\Http\Controllers\Roster;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Roster;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class DutyRosterController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = Roster::with('department')
            ->where('company_id', $this->ctx->id())
            ->where('type', 'duty');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $rosters     = $query->orderByDesc('from_date')->paginate(20)->withQueryString();
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        return view('roster.duty.index', compact('rosters', 'departments'));
    }

    public function create()
    {
        $departments = Department::where('company_id', $this->ctx->id())->orderBy('name')->get();
        return view('roster.duty.create', compact('departments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'department_id' => 'required|exists:departments,id',
            'section_id'    => 'nullable|exists:departments,id',
            'from_date'     => 'required|date',
            'to_date'       => 'required|date|after_or_equal:from_date',
            'roster_year'   => 'nullable|string|max:4',
        ]);

        Roster::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'type'       => 'duty',
            'status'     => 'pending',
            'is_active'  => false,
        ]));

        return redirect()->route('roster.duty.index')->with('success', 'Duty roster created.');
    }

    public function show(Roster $duty)
    {
        $duty->load(['department', 'preparedBy', 'approvedBy']);
        return view('roster.duty.show', compact('duty'));
    }

    public function edit(Roster $duty)
    {
        $departments = Department::where('company_id', $this->ctx->id())->orderBy('name')->get();
        return view('roster.duty.edit', compact('duty', 'departments'));
    }

    public function update(Request $request, Roster $duty)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'department_id' => 'required|exists:departments,id',
            'section_id'    => 'nullable|exists:departments,id',
            'from_date'     => 'required|date',
            'to_date'       => 'required|date|after_or_equal:from_date',
            'roster_year'   => 'nullable|string|max:4',
            'status'        => 'nullable|in:pending,approved,rejected',
        ]);

        $duty->update($data);
        return redirect()->route('roster.duty.index')->with('success', 'Duty roster updated.');
    }

    public function destroy(Roster $duty)
    {
        $duty->delete();
        return back()->with('success', 'Duty roster deleted.');
    }
}
