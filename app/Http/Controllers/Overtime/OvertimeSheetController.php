<?php

namespace App\Http\Controllers\Overtime;

use App\Http\Controllers\Controller;
use App\Models\OvertimeSheet;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class OvertimeSheetController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $sheets = OvertimeSheet::with(['employee', 'overtime', 'payrollSheetDetail.payrollSheet'])
            ->whereHas('employee', fn($q) => $q->where('company_id', $this->ctx->id()))
            ->orderByDesc('date_of_task_done')->paginate(25);
        return view('overtime.sheets.index', compact('sheets'));
    }
}
