<?php

namespace Modules\Reporting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Services\AccessService;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StoreRequisition;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;

/**
 * Headline figures for directors and the director general. Each area appears only when the user may view it;
 * finance, budget, payroll, receivables, fleet, assets and planning come from their own dashboards.
 *
 * @tags Reports: Executive dashboard
 */
class ExecutiveDashboardController extends Controller
{
    private const LIVE_ORDERS = ['approved', 'partial', 'received', 'closed'];

    public function __construct(private readonly AccessService $access) {}

    /**
     * @response array{data: array{months: list<string>, hr: array{headcount: int, on_leave_today: int, contracts_expiring: int, joined_year: int, left_year: int, by_gender: list<array{label: string, value: int}>, by_type: list<array{label: string, value: int}>, by_department: list<array{label: string, value: int}>, joined: list<int>, left: list<int>}|null, procurement: array{requisitions_pending: int, requisitions_to_source: int, orders_pending: int, open_orders: int, open_orders_value: float, ordered_year: float, by_month: list<float>, by_status: list<array{label: string, value: int}>, top_suppliers: list<array{label: string, value: float}>}|null, inventory: array{stock_value: float, items_in_stock: int, below_reorder: int, requisitions_pending: int, requisitions_to_issue: int, by_store: list<array{label: string, value: float}>}|null, payments: array{requests_pending: int, to_voucher: int, to_voucher_value: float, vouchers_to_pay: int, vouchers_to_pay_value: float, paid_year: float, by_month: list<float>, imprest_open: int, imprest_open_value: float, imprest_overdue: int}|null}}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $start = now()->startOfMonth()->subMonths(11);
        $months = collect(range(0, 11))->map(fn ($i) => $start->copy()->addMonths($i));

        return response()->json(['data' => [
            'months' => $months->map(fn (Carbon $m) => $m->format('M Y'))->values(),
            'hr' => $this->can($user, 'hr.employees.view') ? $this->hr($months, $start) : null,
            'procurement' => $this->can($user, 'procurement.view') ? $this->procurement($months, $start) : null,
            'inventory' => $this->can($user, 'inventory.view') ? $this->inventory() : null,
            'payments' => $this->can($user, 'payments.view') ? $this->payments($months, $start) : null,
        ]]);
    }

    private function can(User $user, string $permission): bool
    {
        return $this->access->can($user, $permission);
    }

    private function hr(Collection $months, Carbon $start): array
    {
        $active = Employee::with('department')->where('employment_status', '!=', 'terminated')->get(['id', 'gender', 'emp_type', 'department_id']);
        $today = now()->toDateString();
        $joined = Employee::whereDate('employment_date', '>=', $start)->pluck('employment_date');
        $left = Employee::where('employment_status', 'terminated')->whereDate('termination_date', '>=', $start)->pluck('termination_date');
        $count = fn (Collection $dates) => $months->map(fn (Carbon $m) => $dates->filter(fn ($d) => $d && Carbon::parse($d)->format('Y-m') === $m->format('Y-m'))->count())->values();
        $group = fn (Collection $rows) => $rows->map(fn ($n, $label) => ['label' => (string) ($label ?: 'Not set'), 'value' => $n])->sortByDesc('value')->values();
        $year = now()->startOfYear();

        return [
            'headcount' => $active->count(),
            'on_leave_today' => EmpLeave::whereIn('status', EmpLeave::TAKEN)->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->distinct()->count('employee_id'),
            'contracts_expiring' => EmployeeContract::where('is_active', true)->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', now()->addDays(60))->count(),
            'joined_year' => Employee::whereDate('employment_date', '>=', $year)->count(),
            'left_year' => Employee::where('employment_status', 'terminated')->whereDate('termination_date', '>=', $year)->count(),
            'by_gender' => $group($active->countBy(fn ($e) => ucfirst(strtolower((string) $e->gender)))),
            'by_type' => $group($active->countBy(fn ($e) => ucwords(str_replace('_', ' ', (string) $e->emp_type)))),
            'by_department' => $group($active->countBy(fn ($e) => $e->department?->name))->take(12)->values(),
            'joined' => $count($joined),
            'left' => $count($left),
        ];
    }

    private function procurement(Collection $months, Carbon $start): array
    {
        $orders = PurchaseOrder::with('supplier:id,name')->whereIn('status', self::LIVE_ORDERS)->whereDate('order_date', '>=', $start)->get(['id', 'supplier_id', 'order_date', 'total', 'status']);
        $open = PurchaseOrder::whereIn('status', ['approved', 'partial']);
        $year = now()->startOfYear();

        return [
            'requisitions_pending' => PurchaseRequisition::where('status', 'pending')->count(),
            'requisitions_to_source' => PurchaseRequisition::where('status', 'approved')->count(),
            'orders_pending' => PurchaseOrder::where('status', 'pending')->count(),
            'open_orders' => (clone $open)->count(),
            'open_orders_value' => round((float) (clone $open)->sum('total'), 2),
            'ordered_year' => round((float) PurchaseOrder::whereIn('status', self::LIVE_ORDERS)->whereDate('order_date', '>=', $year)->sum('total'), 2),
            'by_month' => $months->map(fn (Carbon $m) => round((float) $orders->filter(fn ($o) => $o->order_date?->format('Y-m') === $m->format('Y-m'))->sum('total'), 2))->values(),
            'by_status' => PurchaseOrder::whereNotIn('status', ['draft', 'cancelled'])->get(['status'])->countBy('status')
                ->map(fn ($n, $s) => ['label' => PurchaseOrder::STATUS_LABELS[$s] ?? $s, 'value' => $n])->values(),
            'top_suppliers' => $orders->filter(fn ($o) => $o->order_date?->gte($year))->groupBy('supplier_id')
                ->map(fn ($rows) => ['label' => $rows->first()->supplier?->name ?? 'Supplier', 'value' => round((float) $rows->sum('total'), 2)])->sortByDesc('value')->take(8)->values(),
        ];
    }

    private function inventory(): array
    {
        $balances = StockBalance::with('store:id,name')->where('quantity', '>', 0)->get(['id', 'store_id', 'item_id', 'quantity', 'value']);
        $below = StockBalance::query()->join('item_store_levels as l', fn ($j) => $j->on('l.item_id', '=', 'stock_balances.item_id')->on('l.store_id', '=', 'stock_balances.store_id'))
            ->where('l.reorder_level', '>', 0)->whereColumn('stock_balances.quantity', '<=', 'l.reorder_level')->count();

        return [
            'stock_value' => round((float) $balances->sum('value'), 2),
            'items_in_stock' => $balances->pluck('item_id')->unique()->count(),
            'below_reorder' => $below,
            'requisitions_pending' => StoreRequisition::where('status', 'pending')->count(),
            'requisitions_to_issue' => StoreRequisition::whereIn('status', ['approved', 'partial'])->count(),
            'by_store' => $balances->groupBy('store_id')->map(fn ($rows) => ['label' => $rows->first()->store?->name ?? 'Store', 'value' => round((float) $rows->sum('value'), 2)])->sortByDesc('value')->values(),
        ];
    }

    private function payments(Collection $months, Carbon $start): array
    {
        $paid = PaymentVoucher::where('status', 'paid')->whereDate('date', '>=', $start)->get(['date', 'net']);
        $toPay = PaymentVoucher::where('status', 'approved');
        $toVoucher = PaymentRequest::where('status', 'approved');
        $imprest = PaymentRequest::where('kind', 'imprest')->where('status', 'paid');

        return [
            'requests_pending' => PaymentRequest::where('status', 'pending')->count(),
            'to_voucher' => (clone $toVoucher)->count(),
            'to_voucher_value' => round((float) (clone $toVoucher)->sum('total'), 2),
            'vouchers_to_pay' => (clone $toPay)->count(),
            'vouchers_to_pay_value' => round((float) (clone $toPay)->sum('net'), 2),
            'paid_year' => round((float) PaymentVoucher::where('status', 'paid')->whereDate('date', '>=', now()->startOfYear())->sum('net'), 2),
            'by_month' => $months->map(fn (Carbon $m) => round((float) $paid->filter(fn ($v) => $v->date?->format('Y-m') === $m->format('Y-m'))->sum('net'), 2))->values(),
            'imprest_open' => (clone $imprest)->count(),
            'imprest_open_value' => round((float) (clone $imprest)->sum('total'), 2),
            'imprest_overdue' => (clone $imprest)->whereDate('retire_by', '<', now()->toDateString())->count(),
        ];
    }
}
