<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Asset;
use App\Models\AttendanceTransa;
use App\Models\Bill;
use App\Models\DailyAllowance;
use App\Models\Department;
use App\Models\DepartmentAppraisal;
use App\Models\EmpAllowance;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeAppraisal;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\FuelRecord;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Overtime;
use App\Models\OvertimeSheet;
use App\Models\PayrollSheet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SalaryAdjustment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Services\CompanyContext;

class ModuleDashboardController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function hr()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'active_employees'   => Employee::where('company_id', $cid)->where('employment_status', 'active')->count(),
            'new_hires'          => Employee::where('company_id', $cid)->whereMonth('employment_date', now()->month)->whereYear('employment_date', now()->year)->count(),
            'total_departments'  => Department::where('company_id', $cid)->whereNull('parent_id')->count(),
            'expiring_contracts' => Employee::where('company_id', $cid)
                ->whereHas('activeContract', fn($q) => $q->whereBetween('end_date', [now(), now()->addDays(30)]))->count(),
        ];

        $byGender = Employee::where('company_id', $cid)
            ->where('employment_status', 'active')
            ->selectRaw('gender, count(*) as total')
            ->groupBy('gender')->get()->keyBy('gender');

        $byDept = Department::where('company_id', $cid)
            ->whereNull('parent_id')
            ->withCount(['employees' => fn($q) => $q->where('employment_status', 'active')])
            ->orderByDesc('employees_count')->take(8)->get();

        return view('dashboard.modules.hr', compact('stats', 'byGender', 'byDept'));
    }

    public function roster()
    {
        return view('dashboard.modules.roster');
    }

    public function leave()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'pending'    => EmpLeave::where('company_id', $cid)->where('status', 'pending')->count(),
            'on_leave'   => EmpLeave::where('company_id', $cid)->where('is_active', true)->count(),
            'approved'   => EmpLeave::where('company_id', $cid)->whereIn('status', ['hod_approved','hr_approved'])->whereMonth('start_date', now()->month)->count(),
            'rejected'   => EmpLeave::where('company_id', $cid)->where('status', 'rejected')->whereMonth('start_date', now()->month)->count(),
        ];

        $byType = EmpLeave::where('company_id', $cid)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('leave_type_id, count(*) as total')
            ->with('leaveType')->groupBy('leave_type_id')
            ->orderByDesc('total')->take(6)->get();

        return view('dashboard.modules.leave', compact('stats', 'byType'));
    }

    public function attendance()
    {
        $cid   = $this->ctx->id();
        $today = now()->toDateString();
        $stats = [
            'today'      => AttendanceTransa::where('company_id', $cid)->whereDate('transaction_date', $today)->count(),
            'this_month' => AttendanceTransa::where('company_id', $cid)->whereMonth('transaction_date', now()->month)->whereYear('transaction_date', now()->year)->count(),
            'last_month' => AttendanceTransa::where('company_id', $cid)->whereMonth('transaction_date', now()->subMonth()->month)->whereYear('transaction_date', now()->subMonth()->year)->count(),
        ];

        return view('dashboard.modules.attendance', compact('stats'));
    }

    public function allowance()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'pending_emp'   => EmpAllowance::where('company_id', $cid)->where('status', 'pending')->count(),
            'approved_emp'  => EmpAllowance::where('company_id', $cid)->where('status', 'approved')->whereMonth('start_date', now()->month)->count(),
            'pending_daily' => DailyAllowance::where('company_id', $cid)->where('is_hr_approved', false)->count(),
        ];

        return view('dashboard.modules.allowance', compact('stats'));
    }

    public function overtime()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'records_month'  => Overtime::where('company_id', $cid)->whereMonth('date_of_task_done', now()->month)->count(),
            'pending'        => Overtime::where('company_id', $cid)->where('status', 'pending')->count(),
            'pending_sheets' => OvertimeSheet::where('company_id', $cid)->where('status', 'draft')->count(),
            'total_hours'    => Overtime::where('company_id', $cid)->whereMonth('date_of_task_done', now()->month)->sum('hours'),
        ];

        return view('dashboard.modules.overtime', compact('stats'));
    }

    public function payroll()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'total_sheets'  => PayrollSheet::where('company_id', $cid)->count(),
            'draft'         => PayrollSheet::where('company_id', $cid)->where('status', 'draft')->count(),
            'adjustments'   => SalaryAdjustment::where('company_id', $cid)->where('status', 'pending')->count(),
        ];

        $recent = PayrollSheet::where('company_id', $cid)->latest()->take(5)->get();

        return view('dashboard.modules.payroll', compact('stats', 'recent'));
    }

    public function loans()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'active'          => EmployeeLoan::where('company_id', $cid)->where('status', 'active')->count(),
            'pending'         => EmployeeLoan::where('company_id', $cid)->where('status', 'pending')->count(),
            'advances_month'  => EmployeeMonthlyAdvance::where('company_id', $cid)->whereMonth('created_at', now()->month)->count(),
            'total_remain'    => EmployeeLoan::where('company_id', $cid)->where('status', 'active')->sum('remain'),
        ];

        return view('dashboard.modules.loans', compact('stats'));
    }

    public function accounting()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'total_accounts' => Account::where('company_id', $cid)->count(),
            'journals_month' => JournalEntry::where('company_id', $cid)->whereMonth('date', now()->month)->count(),
            'unposted'       => JournalEntry::where('company_id', $cid)->where('status', 'draft')->count(),
        ];

        return view('dashboard.modules.accounting', compact('stats'));
    }

    public function bills()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'unpaid'      => Bill::where('company_id', $cid)->where('status', 'unpaid')->count(),
            'total_due'   => Bill::where('company_id', $cid)->where('status', 'unpaid')->sum('amount'),
            'paid_month'  => Bill::where('company_id', $cid)->where('status', 'paid')->whereMonth('updated_at', now()->month)->count(),
            'suppliers'   => Supplier::where('company_id', $cid)->count(),
        ];

        return view('dashboard.modules.bills', compact('stats'));
    }

    public function procurement()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'pending_prs'   => PurchaseRequest::where('company_id', $cid)->where('status', 'pending')->count(),
            'approved_prs'  => PurchaseRequest::where('company_id', $cid)->where('status', 'approved')->whereMonth('created_at', now()->month)->count(),
            'orders_month'  => PurchaseOrder::where('company_id', $cid)->whereMonth('created_at', now()->month)->count(),
        ];

        return view('dashboard.modules.procurement', compact('stats'));
    }

    public function assets()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'total'    => Asset::where('company_id', $cid)->count(),
            'in_use'   => Asset::where('company_id', $cid)->where('status', 'in-use')->count(),
            'available'=> Asset::where('company_id', $cid)->where('status', 'available')->count(),
            'disposed' => Asset::where('company_id', $cid)->where('status', 'disposed')->count(),
        ];

        $byCategory = Asset::where('company_id', $cid)
            ->selectRaw('asset_category_id, count(*) as total')
            ->with('category')->groupBy('asset_category_id')
            ->orderByDesc('total')->take(6)->get();

        return view('dashboard.modules.assets', compact('stats', 'byCategory'));
    }

    public function fleet()
    {
        $cid        = $this->ctx->id();
        $vehicleIds = Vehicle::where('company_id', $cid)->pluck('id');

        $stats = [
            'total_vehicles'  => $vehicleIds->count(),
            'active'          => Vehicle::where('company_id', $cid)->where('status', 'active')->count(),
            'fuel_liters'     => FuelRecord::whereIn('vehicle_id', $vehicleIds)->whereMonth('date', now()->month)->sum('liters'),
            'maintenance'     => VehicleMaintenanceLog::whereIn('vehicle_id', $vehicleIds)->whereMonth('date', now()->month)->count(),
        ];

        return view('dashboard.modules.fleet', compact('stats'));
    }

    public function inventory()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'total_items' => InventoryItem::where('company_id', $cid)->count(),
            'low_stock'   => InventoryItem::where('company_id', $cid)->where('is_active', true)->whereColumn('quantity_on_hand', '<=', 'minimum_stock')->count(),
            'movements'   => StockMovement::where('company_id', $cid)->whereMonth('created_at', now()->month)->count(),
        ];

        $lowStockItems = InventoryItem::where('company_id', $cid)
            ->where('is_active', true)
            ->whereColumn('quantity_on_hand', '<=', 'minimum_stock')
            ->with('category')->take(5)->get();

        return view('dashboard.modules.inventory', compact('stats', 'lowStockItems'));
    }

    public function appraisals()
    {
        $cid   = $this->ctx->id();
        $stats = [
            'total'     => EmployeeAppraisal::where('company_id', $cid)->count(),
            'pending'   => EmployeeAppraisal::where('company_id', $cid)->whereIn('status', ['pending','in_progress'])->count(),
            'completed' => EmployeeAppraisal::where('company_id', $cid)->where('status', 'completed')->count(),
            'templates' => DepartmentAppraisal::where('company_id', $cid)->count(),
        ];

        return view('dashboard.modules.appraisals', compact('stats'));
    }

    public function reports()
    {
        return view('dashboard.modules.reports');
    }
}
