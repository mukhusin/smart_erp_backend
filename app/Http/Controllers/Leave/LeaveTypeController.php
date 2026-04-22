<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $types = LeaveType::where('company_id', $this->ctx->id())
            ->orderBy('name')->paginate(20);
        return view('leave.types.index', compact('types'));
    }

    public function create()
    {
        return view('leave.types.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'code'        => 'required|string|max:20',
            'gender'      => 'required|in:all,male,female',
            'description' => 'nullable|string',
            'max_days'    => 'required|integer|min:1',
        ]);

        LeaveType::create(array_merge($data, [
            'company_id' => $this->ctx->id(),
            'with_pay'   => $request->boolean('with_pay'),
            'is_active'  => true,
        ]));

        return redirect()->route('leave.types.index')->with('success', 'Leave type created.');
    }

    public function edit(LeaveType $type)
    {
        return view('leave.types.edit', compact('type'));
    }

    public function update(Request $request, LeaveType $type)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'code'        => 'required|string|max:20',
            'gender'      => 'required|in:all,male,female',
            'description' => 'nullable|string',
            'max_days'    => 'required|integer|min:1',
        ]);

        $type->update(array_merge($data, [
            'with_pay'  => $request->boolean('with_pay'),
            'is_active' => $request->boolean('is_active'),
        ]));

        return redirect()->route('leave.types.index')->with('success', 'Leave type updated.');
    }

    public function destroy(LeaveType $type)
    {
        $type->delete();
        return back()->with('success', 'Leave type deleted.');
    }
}
