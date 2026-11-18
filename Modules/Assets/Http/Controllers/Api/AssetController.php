<?php

namespace Modules\Assets\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FundSource;
use Modules\Assets\Actions\ManageAssets;
use Modules\Assets\Http\Requests\AssetImportRequest;
use Modules\Assets\Http\Requests\AssetRequest;
use Modules\Assets\Http\Requests\AssetStatusRequest;
use Modules\Assets\Http\Requests\AssignAssetRequest;
use Modules\Assets\Http\Requests\ReturnAssetRequest;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetChange;
use Modules\Assets\Models\AssetDisposal;
use Modules\Assets\Models\AssetEntry;
use Modules\Assets\Models\AssetReport;
use Modules\Assets\Models\AssetTransfer;
use Modules\Assets\Services\Depreciation;
use Modules\Assets\Support\AssetAccess;
use Modules\Inventory\Models\ItemCategory;
use Modules\Procurement\Models\Supplier;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Assets: register
 */
class AssetController extends Controller
{
    public function __construct(private readonly ManageAssets $actions, private readonly AssetAccess $access) {}

    private function guard(Request $request): void
    {
        abort_unless($this->access->sees($request->user()), 403);
    }

    /**
     * Figures for the assets dashboard.
     *
     * @response array{data: array{cost: float, book_value: float, depreciation: float, count: int, by_category: list<array{category: string, count: int, cost: float, book_value: float}>, by_status: list<array{status: string, label: string, count: int}>, drafts: int, pending: int, open_reports: int, unconfirmed: int, held_by_leavers: int, fully_depreciated: int, next_period: string, last_run: string|null}}
     */
    public function dashboard(Request $request, Depreciation $depreciation): JsonResponse
    {
        $this->guard($request);
        $sums = AssetEntry::query()->join('assets', 'assets.id', '=', 'asset_entries.asset_id')->where('assets.company_id', app(\App\Services\CompanyContext::class)->id())
            ->whereIn('assets.status', Asset::ON_BOOKS)->groupBy('assets.item_category_id')
            ->selectRaw('assets.item_category_id, COUNT(DISTINCT assets.id) as n, SUM(asset_entries.cost) as cost, SUM(asset_entries.depreciation) as dep')->get();
        $categories = ItemCategory::whereIn('id', $sums->pluck('item_category_id'))->pluck('name', 'id');
        $leavers = Employee::where('employment_status', 'terminated')->pluck('id');
        $onBooks = Asset::whereIn('status', Asset::ON_BOOKS);
        $fully = (clone $onBooks)->whereNotNull('dep_until')->whereColumn('dep_charged_to', '>=', 'dep_until')->count();

        return response()->json(['data' => [
            'cost' => round((float) $sums->sum('cost'), 2), 'book_value' => round((float) $sums->sum('cost') - (float) $sums->sum('dep'), 2), 'depreciation' => round((float) $sums->sum('dep'), 2),
            'count' => (clone $onBooks)->count(),
            'by_category' => $sums->map(fn ($s) => ['category' => $categories[$s->item_category_id] ?? '—', 'count' => (int) $s->n, 'cost' => round((float) $s->cost, 2), 'book_value' => round((float) $s->cost - (float) $s->dep, 2)])->sortByDesc('cost')->values(),
            'by_status' => Asset::query()->groupBy('status')->selectRaw('status, COUNT(*) as n')->get()->map(fn ($r) => ['status' => $r->status, 'label' => Asset::STATUS_LABELS[$r->status] ?? $r->status, 'count' => (int) $r->n])->values(),
            'drafts' => Asset::whereIn('status', ['draft', 'rejected'])->count(),
            'pending' => Asset::where('status', 'pending')->count() + AssetChange::where('status', 'pending')->count() + AssetTransfer::where('status', 'pending')->count() + AssetDisposal::where('status', 'pending')->count(),
            'open_reports' => AssetReport::whereIn('status', ['open', 'repair'])->count(),
            'unconfirmed' => (clone $onBooks)->whereNotNull('employee_id')->where(fn ($q) => $q->whereNull('confirmed_year')->orWhere('confirmed_year', '<', today()->year))->count(),
            'held_by_leavers' => (clone $onBooks)->whereIn('employee_id', $leavers)->count(), 'fully_depreciated' => $fully,
            'next_period' => $depreciation->nextPeriod()->toDateString(), 'last_run' => $depreciation->lastRun()?->period_end->toDateString(),
        ]]);
    }

    /** @response array{data: array{categories: list<array{id: int, code: string, name: string, type: string, useful_life_months: int|null, residual_percent: float}>, classes: list<array{value: string, label: string}>, details: array<string, list<array{key: string, label: string}>>, acquisition: list<array{value: string, label: string}>, conditions: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, change_kinds: list<array{value: string, label: string}>, disposal_methods: list<array{value: string, label: string}>, departments: list<array{id: int, name: string}>, branches: list<array{id: int, name: string}>, employees: list<array{id: int, name: string, department_id: int|null}>, fund_sources: list<array{id: int, code: string, name: string}>, suppliers: list<array{id: int, name: string}>, accounts: list<array{id: int, code: string, name: string, type: string}>, banks: list<array{id: int, name: string}>}} */
    public function lookups(Request $request): JsonResponse
    {
        $opt = fn (array $a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['data' => [
            'categories' => ItemCategory::whereIn('type', ['asset', 'intangible', 'wip'])->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type, 'useful_life_months' => $c->useful_life_months, 'residual_percent' => (float) $c->residual_percent])->values(),
            'classes' => $opt(Asset::CLASSES), 'details' => collect(Asset::DETAILS)->map(fn ($f) => collect($f)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()),
            'acquisition' => $opt(Asset::ACQUISITION), 'conditions' => $opt(Asset::CONDITIONS), 'statuses' => $opt(Asset::STATUS_LABELS),
            'change_kinds' => $opt(AssetChange::KINDS), 'disposal_methods' => $opt(AssetDisposal::METHODS),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'branches' => CompanyBranch::orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'employees' => Employee::where('employment_status', '!=', 'terminated')->orderBy('fname')->get(['id', 'fname', 'lname', 'department_id'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => trim($e->fname.' '.$e->lname), 'department_id' => $e->department_id])->values(),
            'fund_sources' => FundSource::where('is_active', true)->orderBy('code')->get()->map(fn ($f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name])->values(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values(),
            'banks' => CashBankAccount::where('is_active', true)->orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
        ]]);
    }

    /**
     * The register, with values at a date.
     *
     * @response array{data: list<array{id: int, number: string, name: string, category: string|null, asset_class: string, serial_number: string|null, tag: string|null, holder: string|null, department: string|null, location: string|null, status: string, status_label: string, condition: string, cost: float, book_value: float, acquisition_date: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('status', description: 'A status, `on_books` (default) or `all`.', type: 'string')]
    #[QueryParameter('category_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('holder', description: '`leavers` (held by employees who left), `unconfirmed` (not confirmed this year), `nobody`.', type: 'string')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $this->guard($request);
        $status = $request->query('status', 'on_books');
        $page = Asset::with(['category', 'department', 'employee'])
            ->when($status === 'on_books', fn ($q) => $q->whereIn('status', Asset::ON_BOOKS), fn ($q) => $q->when($status !== 'all', fn ($q) => $q->where('status', $status)))
            ->when($request->query('category_id'), fn ($q, $c) => $q->where('item_category_id', $c))
            ->when($request->query('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->query('holder') === 'leavers', fn ($q) => $q->whereIn('employee_id', Employee::where('employment_status', 'terminated')->pluck('id')))
            ->when($request->query('holder') === 'unconfirmed', fn ($q) => $q->whereNotNull('employee_id')->where(fn ($q) => $q->whereNull('confirmed_year')->orWhere('confirmed_year', '<', today()->year)))
            ->when($request->query('holder') === 'nobody', fn ($q) => $q->whereNull('employee_id')->whereNull('department_id'))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('serial_number', 'like', "%{$s}%")->orWhere('tag', 'like', "%{$s}%")))
            ->orderByDesc('id')->paginate(min((int) $request->query('per_page', 10), 200));
        $values = AssetEntry::whereIn('asset_id', collect($page->items())->pluck('id'))->groupBy('asset_id')->selectRaw('asset_id, SUM(cost) as c, SUM(depreciation) as d')->get()->keyBy('asset_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Asset $a) => ['id' => $a->id, 'number' => $a->number, 'name' => $a->name, 'category' => $a->category?->name, 'asset_class' => $a->asset_class,
                'serial_number' => $a->serial_number, 'tag' => $a->tag, 'holder' => $a->employee ? trim($a->employee->fname.' '.$a->employee->lname) : null, 'department' => $a->department?->name,
                'location' => $a->location, 'status' => $a->status, 'status_label' => Asset::STATUS_LABELS[$a->status] ?? $a->status, 'condition' => $a->condition,
                'cost' => isset($values[$a->id]) ? round((float) $values[$a->id]->c, 2) : $a->cost, 'book_value' => isset($values[$a->id]) ? round((float) $values[$a->id]->c - (float) $values[$a->id]->d, 2) : $a->cost,
                'acquisition_date' => $a->acquisition_date->toDateString()])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * Find an asset by the code on its label (asset number, tag or serial number), as read from the QR code.
     *
     * @response array{data: array{id: int, number: string, name: string, holder: string|null, location: string|null, status_label: string, mine: bool, can_view: bool}}
     */
    #[QueryParameter('code', type: 'string', required: true)]
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code'));
        abort_if($code === '', 404);
        $asset = Asset::with(['employee', 'department'])->where(fn ($q) => $q->where('number', $code)->orWhere('tag', $code)->orWhere('serial_number', $code))->first();
        abort_unless($asset, 404, 'No asset has this code.');
        $user = $request->user();
        $mine = $asset->employee?->user_id === $user->id;
        abort_unless($this->access->sees($user) || $mine, 403, 'This asset is not yours to see.');

        return response()->json(['data' => ['id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'holder' => $asset->employee?->full_name ?? $asset->department?->name,
            'location' => $asset->location, 'status_label' => Asset::STATUS_LABELS[$asset->status] ?? $asset->status, 'mine' => $mine, 'can_view' => $this->access->sees($user)]]);
    }

    /**
     * @response array{data: array{id: int, number: string, name: string, description: string|null, item_category_id: int, category: string|null, category_type: string|null, asset_class: string, class_label: string, details: array<string, string|null>, acquisition_type: string, acquisition_label: string, acquisition_date: string, in_use_date: string|null, cost: float, opening_depreciation: float, useful_life_months: int|null, residual_value: float, fund_source_id: int|null, fund_source: string|null, supplier_id: int|null, supplier: string|null, goods_receipt: array{id: int, number: string}|null, serial_number: string|null, tag: string|null, company_branch_id: int|null, branch: string|null, department_id: int|null, department: string|null, employee_id: int|null, holder: string|null, location: string|null, condition: string, condition_label: string, status: string, status_label: string, decision_comment: string|null, waiting_for: string|null, value: array{cost: float, depreciation: float, book_value: float}, depreciation: array{from: string|null, until: string|null, charged_to: string|null, depreciable: bool, monthly: float}, confirmed_year: int|null, journal: array{id: int, reference_no: string|null}|null, entries: list<array{id: int, date: string, kind: string, kind_label: string, cost: float, depreciation: float, description: string|null, journal: array{id: int, reference_no: string|null}|null}>, assignments: list<array{id: int, holder: string|null, department: string|null, location: string|null, from_date: string, to_date: string|null, condition_out: string|null, condition_in: string|null, reason: string|null, returned_from: string|null}>, changes: list<array{id: int, number: string, kind: string, kind_label: string, date: string, amount: float|null, months: int|null, reason: string, status: string, status_label: string, mine: bool}>, transfers: list<array{id: int, number: string, kind: string, date: string, permanent: bool, return_date: string|null, destination: string, reason: string, status: string, status_label: string, mine: bool, can_return: bool}>, disposals: list<array{id: int, number: string, method: string, method_label: string, date: string, proceeds: float, book_value: float|null, gain_loss: float|null, status: string, status_label: string, has_minutes: bool, mine: bool}>, reports: list<array{id: int, type: string, type_label: string, description: string|null, status: string, status_label: string, reported_by: string|null, created_at: string}>, can: array{edit: bool, submit: bool, withdraw: bool, delete: bool, manage: bool}}}
     */
    public function show(Request $request, Asset $asset): JsonResponse
    {
        $this->guard($request);
        $a = $asset->load(['category', 'fundSource', 'supplier', 'branch', 'department', 'employee', 'journal', 'entries.journal', 'assignments.department', 'assignments.employee', 'workflow.steps']);
        $user = $request->user();
        $manage = $this->access->manages($user);
        $gr = $a->goods_receipt_id ? \Modules\Procurement\Models\GoodsReceipt::find($a->goods_receipt_id) : null;
        $days = $a->dep_from && $a->dep_until ? $a->dep_from->diffInDays($a->dep_until) + 1 : 0;
        $monthly = $days ? round(max(0, (float) $a->dep_value - $a->residual_value) / $days * 365 / 12, 2) : 0.0;
        $name = fn ($e) => $e ? trim($e->fname.' '.$e->lname) : null;

        return response()->json(['data' => [
            'id' => $a->id, 'number' => $a->number, 'name' => $a->name, 'description' => $a->description, 'item_category_id' => $a->item_category_id, 'category' => $a->category?->name,
            'category_type' => $a->category?->type, 'asset_class' => $a->asset_class, 'class_label' => Asset::CLASSES[$a->asset_class] ?? $a->asset_class, 'details' => (object) ($a->details ?? []),
            'acquisition_type' => $a->acquisition_type, 'acquisition_label' => Asset::ACQUISITION[$a->acquisition_type] ?? $a->acquisition_type, 'acquisition_date' => $a->acquisition_date->toDateString(),
            'in_use_date' => $a->in_use_date?->toDateString(), 'cost' => $a->cost, 'opening_depreciation' => $a->opening_depreciation, 'useful_life_months' => $a->useful_life_months,
            'residual_value' => $a->residual_value, 'fund_source_id' => $a->fund_source_id, 'fund_source' => $a->fundSource?->code, 'supplier_id' => $a->supplier_id, 'supplier' => $a->supplier?->name,
            'goods_receipt' => $gr ? ['id' => $gr->id, 'number' => $gr->number] : null, 'serial_number' => $a->serial_number, 'tag' => $a->tag, 'company_branch_id' => $a->company_branch_id,
            'branch' => $a->branch?->name, 'department_id' => $a->department_id, 'department' => $a->department?->name, 'employee_id' => $a->employee_id, 'holder' => $name($a->employee),
            'location' => $a->location, 'condition' => $a->condition, 'condition_label' => Asset::CONDITIONS[$a->condition] ?? $a->condition, 'status' => $a->status,
            'status_label' => Asset::STATUS_LABELS[$a->status] ?? $a->status, 'decision_comment' => $a->decision_comment,
            'waiting_for' => $a->status === 'pending' ? $a->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'value' => $a->valueAt(), 'depreciation' => ['from' => $a->dep_from?->toDateString(), 'until' => $a->dep_until?->toDateString(), 'charged_to' => $a->dep_charged_to?->toDateString(),
                'depreciable' => $a->depreciable(), 'monthly' => $monthly], 'confirmed_year' => $a->confirmed_year,
            'journal' => $a->journal ? ['id' => $a->journal->id, 'reference_no' => $a->journal->reference_no] : null,
            'entries' => $a->entries->map(fn ($e) => ['id' => $e->id, 'date' => $e->date->toDateString(), 'kind' => $e->kind, 'kind_label' => AssetEntry::KINDS[$e->kind] ?? $e->kind, 'cost' => $e->cost,
                'depreciation' => $e->depreciation, 'description' => $e->description, 'journal' => $e->journal ? ['id' => $e->journal->id, 'reference_no' => $e->journal->reference_no] : null])->values(),
            'assignments' => $a->assignments->map(fn ($s) => ['id' => $s->id, 'holder' => $name($s->employee), 'department' => $s->department?->name, 'location' => $s->location,
                'from_date' => $s->from_date->toDateString(), 'to_date' => $s->to_date?->toDateString(), 'condition_out' => $s->condition_out, 'condition_in' => $s->condition_in, 'reason' => $s->reason,
                'returned_from' => $s->returned_from])->values(),
            'changes' => AssetChange::where('asset_id', $a->id)->orderByDesc('id')->get()->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'kind' => $c->kind, 'kind_label' => AssetChange::KINDS[$c->kind],
                'date' => $c->date->toDateString(), 'amount' => $c->amount, 'months' => $c->months, 'reason' => $c->reason, 'status' => $c->status, 'status_label' => AssetChange::STATUS_LABELS[$c->status] ?? $c->status,
                'mine' => $c->created_by === $user->id])->values(),
            'transfers' => AssetTransfer::with(['toBranch', 'toDepartment', 'toEmployee'])->where('asset_id', $a->id)->orderByDesc('id')->get()->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'kind' => $t->kind,
                'date' => $t->date->toDateString(), 'permanent' => $t->permanent, 'return_date' => $t->return_date?->toDateString(), 'destination' => $t->destination(), 'reason' => $t->reason,
                'status' => $t->status, 'status_label' => AssetTransfer::STATUS_LABELS[$t->status] ?? $t->status, 'mine' => $t->created_by === $user->id,
                'can_return' => $manage && $t->status === 'approved' && ! $t->permanent && $t->kind === 'internal'])->values(),
            'disposals' => AssetDisposal::where('asset_id', $a->id)->orderByDesc('id')->get()->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'method' => $d->method, 'method_label' => AssetDisposal::METHODS[$d->method],
                'date' => $d->date->toDateString(), 'proceeds' => $d->proceeds, 'book_value' => $d->book_value, 'gain_loss' => $d->gain_loss, 'status' => $d->status,
                'status_label' => AssetDisposal::STATUS_LABELS[$d->status] ?? $d->status, 'has_minutes' => (bool) $d->minutes_path, 'mine' => $d->created_by === $user->id])->values(),
            'reports' => AssetReport::with('reporter')->where('asset_id', $a->id)->orderByDesc('id')->get()->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'type_label' => AssetReport::TYPES[$r->type],
                'description' => $r->description, 'status' => $r->status, 'status_label' => AssetReport::STATUS_LABELS[$r->status] ?? $r->status, 'reported_by' => $r->reporter?->display_name,
                'created_at' => $r->created_at->toIso8601String()])->values(),
            'can' => ['edit' => $manage && $a->editable(), 'submit' => $manage && $a->editable(), 'withdraw' => $a->status === 'pending' && $a->created_by === $user->id,
                'delete' => $manage && $a->editable(), 'manage' => $manage && in_array($a->status, ['registered', 'active', 'stored', 'suspended'], true)],
        ]]);
    }

    /** @response array{data: list<array{id: int, number: string}>} */
    public function store(AssetRequest $request): JsonResponse
    {
        $assets = $this->actions->create($request->validated(), $request->user());

        return response()->json(['data' => collect($assets)->map(fn ($a) => ['id' => $a->id, 'number' => $a->number])->values()]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(AssetRequest $request, Asset $asset): JsonResponse
    {
        $a = $this->actions->update($asset, $request->validated());

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'status' => $a->status]]);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->actions->delete($asset);

        return response()->json(null, 204);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, Asset $asset): JsonResponse
    {
        $a = $this->actions->submit($asset, $request->user());

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'status' => $a->status]]);
    }

    /** @response array{data: array{submitted: int, errors: list<string>}} */
    public function submitDrafts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->actions->submitDrafts($request->user())]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, Asset $asset): JsonResponse
    {
        $a = $this->actions->withdraw($asset, $request->user());

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function assign(AssignAssetRequest $request, Asset $asset): JsonResponse
    {
        $a = $this->actions->assign($asset, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function returnAsset(ReturnAssetRequest $request, Asset $asset): JsonResponse
    {
        $a = $this->actions->returnAsset($asset, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function status(AssetStatusRequest $request, Asset $asset): JsonResponse
    {
        $a = $this->actions->setStatus($asset, $request->validated()['status']);

        return response()->json(['data' => ['id' => $a->id, 'number' => $a->number, 'status' => $a->status]]);
    }

    /** @response array{data: array{created: int, errors: list<string>}} */
    public function import(AssetImportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->actions->import($request->file('file')->getRealPath(), $request->user())]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['category_code', 'name', 'asset_class', 'acquisition_type', 'acquisition_date', 'in_use_date', 'cost', 'accumulated_depreciation', 'useful_life_months', 'serial_number', 'tag', 'department', 'location', 'condition', 'description']);
            fputcsv($out, ['VEH', 'Toyota Land Cruiser T 123 ABC', 'vehicle', 'opening', '2022-03-01', '2022-03-10', '180000000', '72000000', '60', 'JTEBU25J', 'KH-0001', 'Administration', 'Main yard', 'good', '']);
            fputcsv($out, ['ICT', 'Dell Latitude 5440', 'computer', 'opening', '2024-07-15', '', '2500000', '600000', '', 'DL5440-88', 'KH-0002', 'Finance', 'Block A room 3', 'very_good', '']);
            fclose($out);
        }, 'asset-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
