<?php

namespace Modules\Hr\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\GratuityAccrual;
use App\Models\GratuitySetting;
use App\Models\GratuitySettlement;
use App\Models\NatureOfContract;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hr\Actions\ManageGratuity;

/**
 * @tags Payroll: gratuity
 */
class GratuityController extends Controller
{
    public function __construct(private readonly ManageGratuity $gratuity) {}

    /**
     * Gratuity settings: off by default; rate, base, contract types and ledger accounts.
     *
     * @response array{data: array{enabled: bool, rate_percent: float, base: string, contract_types: list<int>, expense_account_id: int|null, liability_account_id: int|null}, options: array{bases: list<array{value: string, label: string}>, contract_types: list<array{value: int, label: string, is_permanent: bool}>, accounts: list<array{id: int, code: string, name: string, type: string}>}}
     */
    public function settings(): JsonResponse
    {
        $s = GratuitySetting::current();
        $accounts = class_exists(\Modules\Accounting\Models\Account::class) ? \Modules\Accounting\Models\Account::where('is_active', true)->where('is_postable', true)
            ->whereIn('type', ['expense', 'liability'])->orderBy('code')->get()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values() : [];

        return response()->json([
            'data' => ['enabled' => (bool) $s->enabled, 'rate_percent' => (float) $s->rate_percent, 'base' => $s->base, 'contract_types' => array_map('intval', $s->eligibleTypes()),
                'expense_account_id' => $s->expense_account_id, 'liability_account_id' => $s->liability_account_id],
            'options' => [
                'bases' => collect(GratuitySetting::BASES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'contract_types' => NatureOfContract::where('is_active', true)->orderBy('contract_name')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->contract_name, 'is_permanent' => (bool) $t->is_permanent])->values(),
                'accounts' => $accounts,
            ],
        ]);
    }

    /** @response array{data: array{enabled: bool, rate_percent: float, base: string, contract_types: list<int>, expense_account_id: int|null, liability_account_id: int|null}, options: array{bases: list<array{value: string, label: string}>, contract_types: list<array{value: int, label: string, is_permanent: bool}>, accounts: list<array{id: int, code: string, name: string, type: string}>}} */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** Accrue gratuity each month on approved payrolls. */
            'enabled' => ['required', 'boolean'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'base' => ['required', 'in:basic,gross'],
            /** Contract types that earn gratuity. */
            'contract_types' => ['required_if:enabled,true', 'array'],
            'contract_types.*' => ['integer', 'exists:nature_of_contracts,id'],
            /** Ledger accounts, used when payroll is posted to accounting. */
            'expense_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'liability_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);
        GratuitySetting::current()->fill($data)->save();

        return $this->settings();
    }

    /**
     * Gratuity register: what each employee has accrued, paid or forfeited, and what is still owed.
     *
     * @response array{data: list<array{employee_id: int, employee_no: string|null, name: string|null, department: string|null, months: int, accrued: float, paid: float, forfeited: float, balance: float, last_month: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}, totals: array{accrued: float, balance: float}}
     */
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('owing', description: 'Only staff with an unsettled balance.', type: 'boolean')]
    #[QueryParameter('sort', description: '`name`, `balance`, `accrued`; prefix `-` for descending.', type: 'string', default: '-balance')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $sortParam = (string) $request->query('sort', '-balance');
        $sort = in_array(ltrim($sortParam, '-'), ['name', 'balance', 'accrued'], true) ? ltrim($sortParam, '-') : 'balance';
        $q = GratuityAccrual::query()->join('employees as e', 'e.id', '=', 'gratuity_accruals.employee_id')->leftJoin('gratuity_settlements as s', 's.id', '=', 'gratuity_accruals.gratuity_settlement_id')
            ->selectRaw("gratuity_accruals.employee_id, COUNT(*) as months, SUM(gratuity_accruals.amount) as accrued,
                SUM(CASE WHEN s.kind = 'paid' THEN gratuity_accruals.amount ELSE 0 END) as paid,
                SUM(CASE WHEN s.kind = 'forfeited' THEN gratuity_accruals.amount ELSE 0 END) as forfeited,
                SUM(CASE WHEN gratuity_accruals.gratuity_settlement_id IS NULL THEN gratuity_accruals.amount ELSE 0 END) as balance,
                MAX(gratuity_accruals.year * 100 + gratuity_accruals.month) as last_ym, MIN(e.fname) as name")
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($q) => $q->where('e.fname', 'like', "%{$t}%")->orWhere('e.lname', 'like', "%{$t}%")->orWhere('e.employee_no', 'like', "%{$t}%")))
            ->groupBy('gratuity_accruals.employee_id')
            ->when($request->boolean('owing'), fn ($q) => $q->havingRaw('SUM(CASE WHEN gratuity_accruals.gratuity_settlement_id IS NULL THEN gratuity_accruals.amount ELSE 0 END) > 0'));
        $totals = ['accrued' => round((float) GratuityAccrual::sum('amount'), 2), 'balance' => round((float) GratuityAccrual::whereNull('gratuity_settlement_id')->sum('amount'), 2)];
        $page = $q->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderBy('gratuity_accruals.employee_id')->paginate(min((int) $request->query('per_page', 10), 100));
        $employees = Employee::with('department')->whereIn('id', collect($page->items())->pluck('employee_id'))->get()->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(function ($r) use ($employees) {
                $e = $employees[$r->employee_id] ?? null;

                return ['employee_id' => (int) $r->employee_id, 'employee_no' => $e?->employee_no, 'name' => $e?->full_name, 'department' => $e?->department?->name, 'months' => (int) $r->months,
                    'accrued' => round((float) $r->accrued, 2), 'paid' => round((float) $r->paid, 2), 'forfeited' => round((float) $r->forfeited, 2), 'balance' => round((float) $r->balance, 2),
                    'last_month' => $r->last_ym ? sprintf('%04d-%02d', intdiv((int) $r->last_ym, 100), (int) $r->last_ym % 100) : null];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'totals' => $totals,
        ]);
    }

    /**
     * One employee's gratuity month by month, with settlements.
     *
     * @response array{data: array{employee: array{id: int, employee_no: string|null, name: string|null, department: string|null}, balance: float, accruals: list<array{id: int, month: string, contract: string|null, base_amount: float, rate_percent: float, amount: float, settled: string|null}>, settlements: list<array{id: int, kind: string, kind_label: string, date: string, amount: float, reference: string|null, notes: string|null, journal_entry_id: int|null, by: string|null}>, banks: list<array{id: int, name: string}>}}
     */
    public function show(Employee $employee): JsonResponse
    {
        $employee->load('department');
        $accruals = GratuityAccrual::with(['contract.natureOfContract', 'settlement'])->where('employee_id', $employee->id)->orderByDesc('year')->orderByDesc('month')->get();

        return response()->json(['data' => [
            'employee' => ['id' => $employee->id, 'employee_no' => $employee->employee_no, 'name' => $employee->full_name, 'department' => $employee->department?->name],
            'balance' => $this->gratuity->balance($employee),
            'accruals' => $accruals->map(fn ($a) => ['id' => $a->id, 'month' => sprintf('%04d-%02d', $a->year, $a->month), 'contract' => $a->contract?->natureOfContract?->contract_name,
                'base_amount' => $a->base_amount, 'rate_percent' => $a->rate_percent, 'amount' => $a->amount,
                'settled' => $a->settlement ? (GratuitySettlement::KINDS[$a->settlement->kind] ?? $a->settlement->kind).' '.$a->settlement->date->toDateString() : null])->values(),
            'settlements' => GratuitySettlement::with('creator')->where('employee_id', $employee->id)->latest('date')->get()->map(fn ($s) => ['id' => $s->id, 'kind' => $s->kind,
                'kind_label' => GratuitySettlement::KINDS[$s->kind] ?? $s->kind, 'date' => $s->date->toDateString(), 'amount' => $s->amount, 'reference' => $s->reference, 'notes' => $s->notes,
                'journal_entry_id' => $s->journal_entry_id, 'by' => $s->creator?->display_name])->values(),
            'banks' => class_exists(\Modules\Accounting\Models\CashBankAccount::class) ? \Modules\Accounting\Models\CashBankAccount::where('is_active', true)->orderBy('name')->get()
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values() : [],
        ]]);
    }

    /** @response array{data: array{id: int, amount: float}} */
    public function settle(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            /** paid (end of contract) or forfeited (not earned, e.g. dismissal). */
            'kind' => ['required', 'in:paid,forfeited'],
            'date' => ['required', 'date'],
            'cash_bank_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $s = $this->gratuity->settle($employee, $data, $request->user());

        return response()->json(['data' => ['id' => $s->id, 'amount' => (float) $s->amount]], 201);
    }
}
