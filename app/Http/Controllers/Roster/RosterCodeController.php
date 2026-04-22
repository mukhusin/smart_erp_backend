<?php

namespace App\Http\Controllers\Roster;

use App\Http\Controllers\Controller;
use App\Models\RosterCode;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class RosterCodeController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $codes = RosterCode::where('company_id', $this->ctx->id())->orderBy('code')->paginate(25);
        return view('roster.codes.index', compact('codes'));
    }

    public function create()
    {
        return view('roster.codes.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'        => 'required|string|max:10',
            'duty'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'start_time'  => 'nullable|date_format:H:i',
            'end_time'    => 'nullable|date_format:H:i',
            'work_hour'   => 'nullable|numeric|min:0|max:24',
        ]);

        RosterCode::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'can_punch'  => $request->boolean('can_punch'),
            'pass_day'   => $request->boolean('pass_day'),
        ]));

        return redirect()->route('roster.codes.index')->with('success', 'Roster code created.');
    }

    public function edit(RosterCode $code)
    {
        return view('roster.codes.edit', compact('code'));
    }

    public function update(Request $request, RosterCode $code)
    {
        $data = $request->validate([
            'code'        => 'required|string|max:10',
            'duty'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'start_time'  => 'nullable|date_format:H:i',
            'end_time'    => 'nullable|date_format:H:i',
            'work_hour'   => 'nullable|numeric|min:0|max:24',
        ]);

        $code->update(array_merge($data, [
            'can_punch' => $request->boolean('can_punch'),
            'pass_day'  => $request->boolean('pass_day'),
        ]));

        return redirect()->route('roster.codes.index')->with('success', 'Roster code updated.');
    }

    public function destroy(RosterCode $code)
    {
        $code->delete();
        return back()->with('success', 'Roster code deleted.');
    }
}
