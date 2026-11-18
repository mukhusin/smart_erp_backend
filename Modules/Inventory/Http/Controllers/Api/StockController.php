<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountingPolicies;
use Modules\Inventory\Http\Requests\InventorySettingsRequest;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLayer;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Support\InventoryAccess;

/**
 * @tags Inventory: stock
 */
class StockController extends Controller
{
    public function __construct(private readonly InventoryAccess $access) {}

    /**
     * Stock on hand per store and item, with value, reorder and expiry flags.
     *
     * @response array{data: list<array{store_id: int, store: string|null, item_id: int, code: string|null, name: string|null, category: string|null, unit: string|null, quantity: float, value: float, average_cost: float, reorder_level: float|null, low: bool, expiring: int, expired: int, last_issue_on: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}, totals: array{value: float, low: int, expiring: int}}
     */
    #[QueryParameter('store_id', type: 'integer')]
    #[QueryParameter('category_id', type: 'integer')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('filter', description: '`low` (at or below reorder level), `expiring`, `zero`.', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesStock($request->user()), 403);
        $stores = $this->access->stores($request->user());
        $days = InventorySetting::current()->expiry_warning_days;
        $soon = today()->addDays($days)->toDateString();
        $query = StockBalance::with(['store', 'item.category', 'item.unit', 'item.levels'])
            ->whereHas('store', fn ($s) => $s->where('kind', '!=', 'transit'))
            ->when($stores !== null, fn ($q) => $q->whereIn('store_id', $stores))
            ->when($request->query('store_id'), fn ($q, $s) => $q->where('store_id', $s))
            ->when($request->query('category_id'), fn ($q, $c) => $q->whereHas('item', fn ($i) => $i->where('item_category_id', $c)))
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('item', fn ($i) => $i->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->query('filter') === 'zero', fn ($q) => $q->where('quantity', '<=', 0))
            ->when($request->query('filter') === 'expiring', fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('stock_layers')->whereColumn('stock_layers.store_id', 'stock_balances.store_id')
                ->whereColumn('stock_layers.item_id', 'stock_balances.item_id')->where('quantity_left', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $soon)))
            ->when($request->query('filter') === 'low', fn ($q) => $q->whereHas('item', fn ($i) => $i->whereNotNull('reorder_level'))->whereRaw('quantity <= (SELECT COALESCE((SELECT l.reorder_level FROM item_store_levels l WHERE l.item_id = stock_balances.item_id AND l.store_id = stock_balances.store_id AND l.reorder_level IS NOT NULL), (SELECT i.reorder_level FROM items i WHERE i.id = stock_balances.item_id)))'));
        $totals = ['value' => round((float) (clone $query)->sum('value'), 2)];
        $page = $query->orderBy('store_id')->orderBy('item_id')->paginate(min((int) $request->query('per_page', 10), 200));
        $layers = StockLayer::whereIn('item_id', collect($page->items())->pluck('item_id'))->where('quantity_left', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $soon)->get();

        return response()->json([
            'data' => collect($page->items())->map(function (StockBalance $b) use ($layers) {
                $level = $b->item?->levels->firstWhere('store_id', $b->store_id)?->reorder_level ?? $b->item?->reorder_level;
                $mine = $layers->where('store_id', $b->store_id)->where('item_id', $b->item_id);

                return ['store_id' => $b->store_id, 'store' => $b->store?->name, 'item_id' => $b->item_id, 'code' => $b->item?->code, 'name' => $b->item?->name, 'category' => $b->item?->category?->name,
                    'unit' => $b->item?->unit?->code, 'quantity' => $b->quantity, 'value' => $b->value, 'average_cost' => $b->averageCost(), 'reorder_level' => $level,
                    'low' => $level !== null && $b->quantity <= $level, 'expiring' => $mine->filter(fn ($l) => $l->expiry_date->gte(today()))->count(),
                    'expired' => $mine->filter(fn ($l) => $l->expiry_date->lt(today()))->count(), 'last_issue_on' => $b->last_issue_on?->toDateString()];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'totals' => $totals + ['low' => 0, 'expiring' => 0],
        ]);
    }

    /**
     * Stock card of one item in one store: every movement with the running balance, and the batches on hand.
     *
     * @response array{item: array{id: int, code: string, name: string, unit: string|null}, store: array{id: int, name: string}, balance: array{quantity: float, value: float, average_cost: float}, costing: string, movements: list<array{id: int, date: string, type: string, type_label: string, reference: string|null, batch: string|null, quantity: float, unit_cost: float, value: float, balance_quantity: float, balance_value: float, department: string|null, by: string|null}>, batches: list<array{id: int, batch_number: string|null, expiry_date: string|null, received_on: string, quantity_left: float, unit_cost: float}>}
     */
    #[QueryParameter('store_id', type: 'integer', required: true)]
    public function card(Request $request, Item $item, AccountingPolicies $policies): JsonResponse
    {
        abort_unless($this->access->seesStock($request->user()), 403);
        $storeId = (int) $request->query('store_id');
        $balance = StockBalance::with('store')->where('store_id', $storeId)->where('item_id', $item->id)->firstOrFail();
        $item->loadMissing('unit');

        return response()->json([
            'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'unit' => $item->unit?->code],
            'store' => ['id' => $balance->store_id, 'name' => $balance->store->name],
            'balance' => ['quantity' => $balance->quantity, 'value' => $balance->value, 'average_cost' => $balance->averageCost()],
            'costing' => \Modules\Accounting\Support\FrameworkCatalog::POLICIES['inventory_costing']['options'][$policies->policy('inventory_costing')] ?? '',
            'movements' => StockMovement::with(['layer', 'department', 'user'])->inAuditPeriod('date')->where('store_id', $storeId)->where('item_id', $item->id)->orderByDesc('id')->limit(1000)->get()
                ->map(fn ($m) => ['id' => $m->id, 'date' => $m->date->toDateString(), 'type' => $m->type, 'type_label' => StockMovement::TYPES[$m->type] ?? $m->type, 'reference' => $m->reference,
                    'batch' => $m->layer?->batch_number, 'quantity' => $m->quantity, 'unit_cost' => $m->unit_cost, 'value' => $m->value, 'balance_quantity' => $m->balance_quantity,
                    'balance_value' => $m->balance_value, 'department' => $m->department?->name, 'by' => $m->user?->display_name])->values(),
            'batches' => StockLayer::where('store_id', $storeId)->where('item_id', $item->id)->where('quantity_left', '>', 0)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('received_on')->get()
                ->map(fn ($l) => ['id' => $l->id, 'batch_number' => $l->batch_number, 'expiry_date' => $l->expiry_date?->toDateString(), 'received_on' => $l->received_on->toDateString(),
                    'quantity_left' => $l->quantity_left, 'unit_cost' => $l->unit_cost])->values(),
        ]);
    }

    /**
     * Items a store holds, with what is available, for requests and issue forms.
     *
     * @response array{data: list<array{id: int, code: string, name: string, unit_id: int, unit: string|null, available: float, units: list<array{unit_id: int, code: string|null, factor: float}>, track_expiry: bool}>}
     */
    #[QueryParameter('store_id', type: 'integer', required: true)]
    #[QueryParameter('search', type: 'string')]
    public function available(Request $request): JsonResponse
    {
        $storeId = (int) $request->query('store_id');
        $balances = StockBalance::where('store_id', $storeId)->pluck('quantity', 'item_id');
        $items = Item::with(['unit', 'units.unit'])->where('is_active', true)->where('type', 'store')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $items->map(fn (Item $i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit_id' => $i->unit_id, 'unit' => $i->unit?->code,
            'available' => (float) ($balances[$i->id] ?? 0), 'units' => $i->units->map(fn ($u) => ['unit_id' => $u->unit_id, 'code' => $u->unit?->code, 'factor' => $u->factor])->values(),
            'track_expiry' => (bool) $i->track_expiry])->values()]);
    }

    /** @response array{data: array{opening_account_id: int|null, donation_account_id: int|null, expiry_warning_days: int, slow_moving_days: int, auto_requisition: bool, costing: string}, accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function settings(Request $request, AccountingPolicies $policies): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $s = InventorySetting::current();

        return response()->json(['data' => ['opening_account_id' => $s->opening_account_id, 'donation_account_id' => $s->donation_account_id, 'expiry_warning_days' => (int) $s->expiry_warning_days, 'auto_requisition' => (bool) $s->auto_requisition,
            'slow_moving_days' => (int) $s->slow_moving_days, 'costing' => \Modules\Accounting\Support\FrameworkCatalog::POLICIES['inventory_costing']['options'][$policies->policy('inventory_costing')] ?? ''],
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->whereIn('type', ['equity', 'revenue', 'liability'])->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values()]);
    }

    /** @response array{data: array{opening_account_id: int|null, donation_account_id: int|null, expiry_warning_days: int, slow_moving_days: int, auto_requisition: bool, costing: string}, accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function updateSettings(InventorySettingsRequest $request, AccountingPolicies $policies): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        InventorySetting::current()->fill($request->validated())->save();

        return $this->settings($request, $policies);
    }
}
