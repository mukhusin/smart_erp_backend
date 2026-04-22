<?php
namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\JobPosition;
use App\Models\SalaryScale;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class SalaryScaleController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $scales = SalaryScale::with('jobPosition')->where('company_id',$this->ctx->id())->latest()->get();
        return view('hr.salary-scales.index', compact('scales'));
    }

    public function create()
    {
        $positions = JobPosition::where('company_id',$this->ctx->id())->orderBy('title')->get();
        return view('hr.salary-scales.create', compact('positions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'job_position_id' => 'nullable|exists:job_positions,id',
            'minimum'         => 'required|numeric|min:0',
            'maximum'         => 'required|numeric|gte:minimum',
            'description'     => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        SalaryScale::create($data);
        return redirect()->route('hr.salary-scales.index')->with('success','Salary scale created.');
    }

    public function edit(SalaryScale $salaryScale)
    {
        $positions = JobPosition::where('company_id',$this->ctx->id())->orderBy('title')->get();
        return view('hr.salary-scales.edit', compact('salaryScale','positions'));
    }

    public function update(Request $request, SalaryScale $salaryScale)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'job_position_id' => 'nullable|exists:job_positions,id',
            'minimum'         => 'required|numeric|min:0',
            'maximum'         => 'required|numeric|gte:minimum',
            'description'     => 'nullable|string',
        ]);
        $salaryScale->update($data);
        return redirect()->route('hr.salary-scales.index')->with('success','Salary scale updated.');
    }

    public function destroy(SalaryScale $salaryScale)
    {
        $salaryScale->delete();
        return back()->with('success','Salary scale deleted.');
    }
}
