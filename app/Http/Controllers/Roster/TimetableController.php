<?php

namespace App\Http\Controllers\Roster;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\RosterTimetable;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $timetables = RosterTimetable::with('department')
            ->where('company_id', $this->ctx->id())
            ->orderBy('title')->paginate(20);
        return view('roster.timetables.index', compact('timetables'));
    }

    public function create()
    {
        $departments = Department::where('company_id', $this->ctx->id())
            ->whereNull('parent_id')->orderBy('name')->get();
        return view('roster.timetables.create', compact('departments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id'  => 'required|exists:departments,id',
            'section_id'     => 'nullable|exists:departments,id',
            'title'          => 'required|string|max:150',
            'work_hour'      => 'required|string',
            'check_in_start' => 'required|date_format:H:i',
            'check_in_end'   => 'required|date_format:H:i',
            'check_out_start'=> 'required|date_format:H:i',
            'check_out_end'  => 'required|date_format:H:i',
            'cross_day'      => 'integer|min:0',
        ]);

        RosterTimetable::create(array_merge($data, [
            'company_id'     => $this->ctx->id(),
            'use_attendance' => $request->boolean('use_attendance'),
            'is_active'      => true,
            'status'         => 'active',
        ]));

        return redirect()->route('roster.timetables.index')->with('success', 'Timetable created.');
    }

    public function edit(RosterTimetable $timetable)
    {
        $departments = Department::where('company_id', $this->ctx->id())
            ->whereNull('parent_id')->orderBy('name')->get();
        return view('roster.timetables.edit', compact('timetable', 'departments'));
    }

    public function update(Request $request, RosterTimetable $timetable)
    {
        $data = $request->validate([
            'department_id'  => 'required|exists:departments,id',
            'section_id'     => 'nullable|exists:departments,id',
            'title'          => 'required|string|max:150',
            'work_hour'      => 'required|string',
            'check_in_start' => 'required|date_format:H:i',
            'check_in_end'   => 'required|date_format:H:i',
            'check_out_start'=> 'required|date_format:H:i',
            'check_out_end'  => 'required|date_format:H:i',
            'cross_day'      => 'integer|min:0',
        ]);

        $timetable->update(array_merge($data, [
            'use_attendance' => $request->boolean('use_attendance'),
            'is_active'      => $request->boolean('is_active'),
        ]));

        return redirect()->route('roster.timetables.index')->with('success', 'Timetable updated.');
    }

    public function destroy(RosterTimetable $timetable)
    {
        $timetable->delete();
        return back()->with('success', 'Timetable deleted.');
    }
}
