<?php

namespace Modules\Budgeting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetEntry;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Support\BudgetAccess;
use Modules\Budgeting\Support\BudgetFigures;

/**
 * @tags Budgeting
 */
class BudgetReportController extends Controller
{
    private const GROUPS = ['department', 'activity', 'sub_activity', 'account', 'fund', 'item'];

    public function __construct(private readonly BudgetAccess $access, private readonly BudgetFigures $figures) {}

    /**
     * Budget vs actual: original, revised, committed, actual, available and use per group.
     *
     * @response array{data: list<array{key: string, label: string, original: float, revised: float, released: float, reserved: float, actual: float, committed: float, available: float, variance: float, used_percent: float|null, items: int}>, totals: array{original: float, revised: float, released: float, reserved: float, actual: float, committed: float, available: float}, group: string}
     */
    #[QueryParameter('group', description: '`department`, `activity`, `sub_activity`, `account`, `fund` or `item`.', type: 'string', default: 'department')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('fund_source_id', type: 'integer')]
    public function vsActual(Request $request, Budget $budget): JsonResponse
    {
        $departments = $this->access->departments($request->user());
        abort_unless($departments !== [], 403);
        $group = in_array($request->query('group'), self::GROUPS, true) ? $request->query('group') : 'department';
        $items = BudgetItem::where('budget_id', $budget->id)
            ->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments))
            ->when($request->query('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->query('fund_source_id'), fn ($q, $f) => $q->where('fund_source_id', $f))->get();
        $rows = $this->figures->items($items);
        [$keyFn, $labelFn] = match ($group) {
            'activity' => [fn ($r) => 'a'.($r['activity']['id'] ?? 0), fn ($r) => $r['activity'] ? trim(($r['activity']['code'] ?? '').' '.$r['activity']['title']) : 'No activity'],
            'sub_activity' => [fn ($r) => 's'.($r['sub_activity']['id'] ?? 0), fn ($r) => $r['sub_activity'] ? trim(($r['sub_activity']['code'] ?? '').' '.$r['sub_activity']['title']) : 'No sub-activity'],
            'account' => [fn ($r) => 'c'.($r['account']['id'] ?? 0), fn ($r) => $r['account'] ? $r['account']['code'].' '.$r['account']['name'].($r['account']['gfs_code'] ? ' (GFS '.$r['account']['gfs_code'].')' : '') : '—'],
            'fund' => [fn ($r) => 'f'.($r['fund_source']['id'] ?? 0), fn ($r) => $r['fund_source']['code'] ?? 'No fund source'],
            'item' => [fn ($r) => 'i'.$r['id'], fn ($r) => $r['label'].' · '.$r['department']],
            default => [fn ($r) => 'd'.$r['department_id'], fn ($r) => $r['department'] ?? '—'],
        };
        $out = $rows->groupBy($keyFn)->map(function ($g, $key) use ($labelFn) {
            $t = $this->figures->totals($g);

            return ['key' => (string) $key, 'label' => $labelFn($g->first())] + $t + ['variance' => round($t['revised'] - $t['actual'], 2),
                'used_percent' => $t['revised'] > 0 ? round(($t['actual'] + $t['committed'] + $t['reserved']) / $t['revised'] * 100, 1) : null, 'items' => $g->count()];
        })->sortBy('label')->values();

        return response()->json(['data' => $out, 'totals' => $this->figures->totals($rows), 'group' => $group]);
    }

    /**
     * Budget items with what is left, for choosing an item to spend against.
     *
     * @response array{data: list<array{id: int, label: string, department_id: int, department: string|null, activity: array{id: int, code: string|null, title: string}|null, sub_activity: array{id: int, code: string|null, title: string}|null, account: array{id: int, code: string, gfs_code: string|null, name: string, type: string}|null, fund_source: array{id: int, code: string}|null, description: string|null, quantity: float, unit: string|null, unit_cost: float, frequency: float, periods: list<float>, original: float, revised: float, released: float, unreleased: float, reserved: float, actual: float, committed: float, available: float, used_percent: float|null}>, budget: array{id: int, title: string}|null}
     */
    #[QueryParameter('department_id', type: 'integer')]
    public function items(Request $request): JsonResponse
    {
        $budget = Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->first();
        if (! $budget) {
            return response()->json(['data' => [], 'budget' => null]);
        }
        $items = BudgetItem::where('budget_id', $budget->id)->when($request->query('department_id'), fn ($q, $d) => $q->where('department_id', $d))->get();

        return response()->json(['data' => $this->figures->items($items)->values(), 'budget' => ['id' => $budget->id, 'title' => $budget->title]]);
    }

    /**
     * A budget item's statement: every balance change with its document, and the ledger lines posted against it.
     *
     * @response array{data: array{id: int, label: string, department_id: int, department: string|null, activity: array{id: int, code: string|null, title: string}|null, sub_activity: array{id: int, code: string|null, title: string}|null, account: array{id: int, code: string, gfs_code: string|null, name: string, type: string}|null, fund_source: array{id: int, code: string}|null, description: string|null, quantity: float, unit: string|null, unit_cost: float, frequency: float, periods: list<float>, original: float, revised: float, released: float, unreleased: float, reserved: float, actual: float, committed: float, available: float, used_percent: float|null}, lines: list<array{journal_id: int, reference_no: string|null, date: string, description: string|null, debit: float, credit: float}>, entries: list<array{id: int, balance: string, balance_label: string, amount: float, source: string|null, source_id: int|null, note: string|null, by: string|null, created_at: string}>}
     */
    public function item(Request $request, BudgetItem $item): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $item->department_id) || $this->access->canPrepare($request->user(), $item->department_id), 403);
        $lines = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->where('l.budget_item_id', $item->id)
            ->orderByDesc('e.date')->orderByDesc('e.id')->limit(500)
            ->select('e.id', 'e.reference_no', 'e.date', DB::raw('COALESCE(l.description, e.description) as description'), 'l.debit', 'l.credit')->get();

        return response()->json([
            'data' => $this->figures->items(collect([$item]))->first(),
            'lines' => $lines->map(fn ($l) => ['journal_id' => (int) $l->id, 'reference_no' => $l->reference_no, 'date' => Carbon::parse($l->date)->toDateString(), 'description' => $l->description, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit])->values(),
            'entries' => BudgetEntry::with('user')->where('budget_item_id', $item->id)->latest('id')->limit(500)->get()
                ->map(fn ($e) => ['id' => $e->id, 'balance' => $e->balance, 'balance_label' => BudgetEntry::BALANCES[$e->balance] ?? $e->balance, 'amount' => (float) $e->amount,
                    'source' => $e->source_type ? class_basename($e->source_type) : null, 'source_id' => $e->source_id ? (int) $e->source_id : null, 'note' => $e->note,
                    'by' => $e->user?->display_name, 'created_at' => $e->created_at->toIso8601String()])->values(),
        ]);
    }
}
