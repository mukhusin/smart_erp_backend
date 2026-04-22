<?php

namespace App\Http\Controllers\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceSheet;
use App\Models\PayrollSheet;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AllowanceSheetController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $sheets = AllowanceSheet::with(['employee', 'payrollSheetDetail.payrollSheet'])
            ->whereHas('employee', fn($q) => $q->where('company_id', $this->ctx->id()))
            ->orderByDesc('created_at')->paginate(25);
        return view('allowance.sheets.index', compact('sheets'));
    }

    public function show(AllowanceSheet $sheet)
    {
        $sheet->load(['employee', 'payrollSheetDetail']);
        return view('allowance.sheets.show', compact('sheet'));
    }

    public function destroy(AllowanceSheet $sheet)
    {
        $sheet->delete();
        return back()->with('success', 'Allowance sheet entry deleted.');
    }
}
