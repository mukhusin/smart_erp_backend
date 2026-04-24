<?php
namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Services\CompanyContext;
use App\Services\ModuleAccessService;

class DashboardController extends Controller
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected CompanyContext $ctx,
    ) {}

    public function index()
    {
        $modules   = $this->moduleAccess->allForDashboard();
        $companyId = $this->ctx->id();

        $kpis = [
            'employees'       => Employee::where('company_id', $companyId)->where('employment_status', 'active')->count(),
            'pending_leaves'  => EmpLeave::where('company_id', $companyId)->whereIn('status', ['pending','hod_approved'])->count(),
            'on_leave'        => EmpLeave::where('company_id', $companyId)->where('is_active', true)->count(),
            'unpaid_bills'    => Bill::where('company_id', $companyId)->where('status', 'unpaid')->count(),
            'pending_prs'     => PurchaseRequest::where('company_id', $companyId)->where('status', 'pending')->count(),
            'low_stock_items' => InventoryItem::where('company_id', $companyId)->where('is_active', true)
                ->whereColumn('quantity_on_hand', '<=', 'minimum_stock')->count(),
        ];

        return view('dashboard.index', compact('modules', 'kpis'));
    }
}
