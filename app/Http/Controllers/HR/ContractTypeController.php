<?php
namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\NatureOfContract;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class ContractTypeController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $types = NatureOfContract::where('company_id',$this->ctx->id())->latest()->get();
        return view('hr.contract-types.index', compact('types'));
    }

    public function create()
    {
        return view('hr.contract-types.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contract_name'       => 'required|string|max:150',
            'code'                => 'required|string|max:20',
            'employment_type'     => 'required|string|max:50',
            'has_annual_leave'    => 'boolean',
            'has_payroll'         => 'boolean',
            'has_overtime'        => 'boolean',
            'has_allowance'       => 'boolean',
            'has_emergency_fund'  => 'boolean',
            'contract_description'=> 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        $data['has_annual_leave']   = $request->boolean('has_annual_leave');
        $data['has_payroll']        = $request->boolean('has_payroll');
        $data['has_overtime']       = $request->boolean('has_overtime');
        $data['has_allowance']      = $request->boolean('has_allowance');
        $data['has_emergency_fund'] = $request->boolean('has_emergency_fund');
        NatureOfContract::create($data);
        return redirect()->route('hr.contract-types.index')->with('success','Contract type created.');
    }

    public function edit(NatureOfContract $contractType)
    {
        return view('hr.contract-types.edit', compact('contractType'));
    }

    public function update(Request $request, NatureOfContract $contractType)
    {
        $data = $request->validate([
            'contract_name'        => 'required|string|max:150',
            'code'                 => 'required|string|max:20',
            'employment_type'      => 'required|string|max:50',
            'contract_description' => 'nullable|string',
        ]);
        $data['has_annual_leave']   = $request->boolean('has_annual_leave');
        $data['has_payroll']        = $request->boolean('has_payroll');
        $data['has_overtime']       = $request->boolean('has_overtime');
        $data['has_allowance']      = $request->boolean('has_allowance');
        $data['has_emergency_fund'] = $request->boolean('has_emergency_fund');
        $data['is_active']          = $request->boolean('is_active');
        $contractType->update($data);
        return redirect()->route('hr.contract-types.index')->with('success','Contract type updated.');
    }

    public function destroy(NatureOfContract $contractType)
    {
        $contractType->delete();
        return back()->with('success','Contract type deleted.');
    }
}
