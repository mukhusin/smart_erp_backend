<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\Department;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Inventory\Actions\ManageCatalogue;
use Modules\Inventory\Actions\ManageStores;
use Modules\Inventory\Http\Requests\ItemCategoryRequest;
use Modules\Inventory\Http\Requests\ItemImportRequest;
use Modules\Inventory\Http\Requests\ItemLevelsRequest;
use Modules\Inventory\Http\Requests\ItemRequest;
use Modules\Inventory\Http\Requests\UnitRequest;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Support\InventoryAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Inventory: catalogue
 */
class CatalogueController extends Controller
{
    public function __construct(private readonly InventoryAccess $access, private readonly ManageCatalogue $catalogue) {}

    /**
     * Choice lists for catalogue and store forms.
     *
     * @response array{data: array{units: list<array{id: int, code: string, name: string, is_active: bool}>, categories: list<array{id: int, code: string, name: string, type: string, type_label: string, is_active: bool}>, types: list<array{value: string, label: string}>, uses: list<array{value: string, label: string}>, store_kinds: list<array{value: string, label: string}>, stores: list<array{id: int, code: string, name: string}>, branches: list<array{id: int, name: string}>, departments: list<array{id: int, name: string}>, accounts: list<array{id: int, code: string, name: string, type: string}>, account_labels: array<string, string>}}
     */
    public function lookups(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $opt = fn (array $a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['data' => [
            'units' => Unit::orderBy('code')->get()->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'is_active' => (bool) $u->is_active])->values(),
            'categories' => ItemCategory::orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type, 'type_label' => ItemCategory::TYPES[$c->type] ?? $c->type, 'is_active' => (bool) $c->is_active])->values(),
            'types' => $opt(ItemCategory::TYPES), 'uses' => $opt(Item::USES), 'store_kinds' => $opt(Store::KINDS),
            'stores' => Store::where('is_active', true)->orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values(),
            'branches' => CompanyBranch::orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values(),
            'account_labels' => ItemCategory::ACCOUNTS,
        ]]);
    }

    /** @response array{data: array{id: int}} */
    public function storeUnit(UnitRequest $request): JsonResponse
    {
        return $this->saveUnit($request, null);
    }

    /** @response array{data: array{id: int}} */
    public function updateUnit(UnitRequest $request, Unit $unit): JsonResponse
    {
        return $this->saveUnit($request, $unit);
    }

    /**
     * Item categories with their accounting and depreciation rules.
     *
     * @response array{data: list<array{id: int, code: string, name: string, type: string, type_label: string, inventory_account_id: int|null, expense_account_id: int|null, revenue_account_id: int|null, asset_account_id: int|null, depreciation_account_id: int|null, accumulated_account_id: int|null, useful_life_months: int|null, residual_percent: float, capitalisation_threshold: float|null, is_active: bool, items: int, accounts_text: string}>}
     */
    public function categories(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $accounts = Account::get(['id', 'code', 'name'])->keyBy('id');

        return response()->json(['data' => ItemCategory::withCount('items')->orderBy('name')->get()->map(function (ItemCategory $c) use ($accounts) {
            $text = collect(ItemCategory::ACCOUNTS)->keys()->filter(fn ($k) => $c->{"{$k}_account_id"})->map(fn ($k) => ucfirst($k).': '.($accounts[$c->{"{$k}_account_id"}]->code ?? '?'))->join(' · ');

            return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type, 'type_label' => ItemCategory::TYPES[$c->type] ?? $c->type,
                'inventory_account_id' => $c->inventory_account_id, 'expense_account_id' => $c->expense_account_id, 'revenue_account_id' => $c->revenue_account_id,
                'asset_account_id' => $c->asset_account_id, 'depreciation_account_id' => $c->depreciation_account_id, 'accumulated_account_id' => $c->accumulated_account_id,
                'useful_life_months' => $c->useful_life_months, 'residual_percent' => (float) $c->residual_percent, 'capitalisation_threshold' => $c->capitalisation_threshold,
                'is_active' => (bool) $c->is_active, 'items' => (int) $c->items_count, 'accounts_text' => $text];
        })->values()]);
    }

    /** @response array{data: array{id: int}} */
    public function storeCategory(ItemCategoryRequest $request): JsonResponse
    {
        return $this->saveCategory($request, null);
    }

    /** @response array{data: array{id: int}} */
    public function updateCategory(ItemCategoryRequest $request, ItemCategory $category): JsonResponse
    {
        return $this->saveCategory($request, $category);
    }

    /**
     * The item catalogue shared by all stores.
     *
     * @response array{data: list<array{id: int, code: string, name: string, category: string|null, category_id: int, type: string, type_label: string, use: string, use_label: string, unit: string|null, reorder_level: float|null, last_cost: float|null, selling_price: float|null, is_active: bool}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Code, name or barcode.', type: 'string')]
    #[QueryParameter('category_id', type: 'integer')]
    #[QueryParameter('type', type: 'string')]
    #[QueryParameter('active', type: 'boolean')]
    #[QueryParameter('sort', description: '`name`, `code`; prefix `-` for descending.', type: 'string', default: 'name')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function items(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $sortParam = (string) $request->query('sort', 'name');
        $sort = in_array(ltrim($sortParam, '-'), ['name', 'code'], true) ? ltrim($sortParam, '-') : 'name';
        $page = Item::with(['category', 'unit'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('barcode', $s)))
            ->when($request->query('category_id'), fn ($q, $c) => $q->where('item_category_id', $c))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 10), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Item $i) => [
                'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'category' => $i->category?->name, 'category_id' => $i->item_category_id, 'type' => $i->type,
                'type_label' => ItemCategory::TYPES[$i->type] ?? $i->type, 'use' => $i->use, 'use_label' => Item::USES[$i->use] ?? $i->use, 'unit' => $i->unit?->code,
                'reorder_level' => $i->reorder_level, 'last_cost' => $i->last_cost, 'selling_price' => $i->selling_price, 'is_active' => (bool) $i->is_active,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * An item with its unit conversions and per-store levels.
     *
     * @response array{data: array{id: int, code: string, name: string, description: string|null, item_category_id: int, category: string|null, type: string, type_label: string, use: string, unit_id: int, unit: string|null, track_batch: bool, track_expiry: bool, track_serial: bool, min_level: float|null, max_level: float|null, reorder_level: float|null, last_cost: float|null, selling_price: float|null, barcode: string|null, is_active: bool, units: list<array{unit_id: int, code: string|null, factor: float}>, levels: list<array{store_id: int, store: string|null, min_level: float|null, max_level: float|null, reorder_level: float|null, store_bin_id: int|null, bin: string|null}>, can: array{edit: bool}}}
     */
    public function item(Request $request, Item $item): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $i = $item->load(['category', 'unit', 'units.unit', 'levels.store', 'levels.bin']);

        return response()->json(['data' => [
            'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'description' => $i->description, 'item_category_id' => $i->item_category_id, 'category' => $i->category?->name,
            'type' => $i->type, 'type_label' => ItemCategory::TYPES[$i->type] ?? $i->type, 'use' => $i->use, 'unit_id' => $i->unit_id, 'unit' => $i->unit?->code,
            'track_batch' => (bool) $i->track_batch, 'track_expiry' => (bool) $i->track_expiry, 'track_serial' => (bool) $i->track_serial,
            'min_level' => $i->min_level, 'max_level' => $i->max_level, 'reorder_level' => $i->reorder_level, 'last_cost' => $i->last_cost, 'selling_price' => $i->selling_price,
            'barcode' => $i->barcode, 'is_active' => (bool) $i->is_active,
            'units' => $i->units->map(fn ($u) => ['unit_id' => $u->unit_id, 'code' => $u->unit?->code, 'factor' => $u->factor])->values(),
            'levels' => $i->levels->map(fn ($l) => ['store_id' => $l->store_id, 'store' => $l->store?->name, 'min_level' => $l->min_level, 'max_level' => $l->max_level,
                'reorder_level' => $l->reorder_level, 'store_bin_id' => $l->store_bin_id, 'bin' => $l->bin?->code])->values(),
            'can' => ['edit' => $this->access->can($request->user(), 'catalogue')],
        ]]);
    }

    /** @response array{data: array{id: int, code: string}} */
    public function storeItem(ItemRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'catalogue'), 403);
        $this->unique(Item::class, 'code', $request->validated('code'), null);
        $i = $this->catalogue->saveItem(null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $i->id, 'code' => $i->code]], 201);
    }

    /** @response array{data: array{id: int, code: string}} */
    public function updateItem(ItemRequest $request, Item $item): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'catalogue'), 403);
        $this->unique(Item::class, 'code', $request->validated('code'), $item->id);
        $i = $this->catalogue->saveItem($item, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $i->id, 'code' => $i->code]]);
    }

    public function levels(ItemLevelsRequest $request, Item $item, ManageStores $stores): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'catalogue') || $this->access->can($request->user(), 'stores'), 403);
        $stores->levels($item, $request->validated('levels'));

        return response()->json(['message' => 'Store levels saved.']);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['code', 'name', 'category', 'unit', 'use', 'reorder_level', 'min_level', 'max_level', 'description']);
            fputcsv($out, ['', 'A4 paper 80gsm', 'STA', 'BOX', 'internal', '20', '10', '100', 'Leave code empty to number it from the category']);
            fputcsv($out, ['', 'Paracetamol 500mg tablets', 'MED', 'PCS', 'both', '5000', '', '', '']);
            fclose($out);
        }, 'item-catalogue-template.csv', ['Content-Type' => 'text/csv']);
    }

    /** @response array{data: array{created: int, updated: int, errors: list<string>}} */
    public function import(ItemImportRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'catalogue'), 403);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \xEF\xBB\xBF")), fgetcsv($handle) ?: []);
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if (array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')) ?: [];
            }
        }
        fclose($handle);

        return response()->json(['data' => $this->catalogue->import($rows, $request->user())]);
    }

    private function saveUnit(UnitRequest $request, ?Unit $unit): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'catalogue'), 403);
        $this->unique(Unit::class, 'code', $request->validated('code'), $unit?->id);
        $u = $this->catalogue->saveUnit($unit, $request->validated());

        return response()->json(['data' => ['id' => $u->id]], $unit ? 200 : 201);
    }

    private function saveCategory(ItemCategoryRequest $request, ?ItemCategory $category): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'catalogue'), 403);
        $this->unique(ItemCategory::class, 'code', $request->validated('code'), $category?->id);
        $c = $this->catalogue->saveCategory($category, $request->validated());

        return response()->json(['data' => ['id' => $c->id]], $category ? 200 : 201);
    }

    /** @param class-string<Model> $model */
    private function unique(string $model, string $field, ?string $value, ?int $except): void
    {
        if ($value && $model::where($field, $value)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages([$field => "The code {$value} is already used."]);
        }
    }
}
