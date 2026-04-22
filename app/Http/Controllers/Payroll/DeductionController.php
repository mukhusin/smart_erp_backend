<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollDateSetting;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class DeductionController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $setting = PayrollDateSetting::where('company_id', $this->ctx->id())->first();
        return view('payroll.deductions.index', compact('setting'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'from_day'          => 'required|integer|min:1|max:31',
            'to_day'            => 'required|integer|min:1|max:31',
            'from_month'        => 'required|in:last,current',
            'to_month'          => 'required|in:last,current',
            'wcf'               => 'required|numeric|min:0',
            'sdl'               => 'required|numeric|min:0',
            'heslb'             => 'required|numeric|min:0',
            'paye'              => 'required|numeric|min:0',
            'income_tax'        => 'required|numeric|min:0',
            'nhif_employee'     => 'required|numeric|min:0',
            'nhif_employer'     => 'required|numeric|min:0',
            'nssf_employer'     => 'required|numeric|min:0',
            'nssf_employee'     => 'required|numeric|min:0',
            'emergency_fund'    => 'required|numeric|min:0',
        ]);

        $data = array_merge($data, [
            'use_overtime'       => $request->boolean('use_overtime'),
            'use_loan'           => $request->boolean('use_loan'),
            'use_allowance'      => $request->boolean('use_allowance'),
            'use_attendance'     => $request->boolean('use_attendance'),
            'use_leave_deduction'=> $request->boolean('use_leave_deduction'),
            'use_deduction'      => $request->boolean('use_deduction'),
        ]);

        PayrollDateSetting::updateOrCreate(
            ['company_id' => $this->ctx->id()],
            array_merge($data, ['company_id' => $this->ctx->id(), 'is_active' => true])
        );

        return back()->with('success', 'Payroll settings saved.');
    }
}
