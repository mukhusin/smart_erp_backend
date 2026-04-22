<?php
namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\JobPosition;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class JobPositionController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $positions = JobPosition::with('department')->where('company_id',$this->ctx->id())->withCount('employees')->latest()->get();
        return view('hr.positions.index', compact('positions'));
    }

    public function create()
    {
        $departments = Department::where('company_id',$this->ctx->id())->orderBy('name')->get();
        return view('hr.positions.create', compact('departments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'department_id' => 'nullable|exists:departments,id',
            'description'   => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        JobPosition::create($data);
        return redirect()->route('hr.positions.index')->with('success','Job position created.');
    }

    public function edit(JobPosition $position)
    {
        $departments = Department::where('company_id',$this->ctx->id())->orderBy('name')->get();
        return view('hr.positions.edit', compact('position','departments'));
    }

    public function update(Request $request, JobPosition $position)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'department_id' => 'nullable|exists:departments,id',
            'description'   => 'nullable|string',
        ]);
        $position->update($data);
        return redirect()->route('hr.positions.index')->with('success','Job position updated.');
    }

    public function destroy(JobPosition $position)
    {
        if ($position->employees()->count()) return back()->with('error','Cannot delete a position assigned to employees.');
        $position->delete();
        return back()->with('success','Position deleted.');
    }
}
