<?php

namespace Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Payments\Actions\ManagePettyCash;
use Modules\Payments\Models\PettyCashFloat;
use Modules\Payments\Models\PettyCashReplenishment;
use Modules\Payments\Models\PettyCashVoucher;
use Modules\Payments\Support\PaymentAccess;
use Modules\Payments\Support\PaymentFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Payments: petty cash
 */
class PettyCashController extends Controller
{
    public function __construct(private readonly PaymentAccess $access, private readonly ManagePettyCash $petty) {}

    /**
     * Petty cash floats the user keeps or oversees, with balances.
     *
     * @response array{data: list<array{id: int, cash_bank_account_id: int, name: string|null, custodian_id: int, custodian: string|null, float_amount: float, max_voucher: float|null, replenish_from_id: int|null, replenish_from: string|null, is_active: bool, balance: float, available: float, pending: int, spent_since_top_up: float, mine: bool}>, can_manage: bool, cash_accounts: list<array{id: int, name: string, kind: string}>, departments: list<array{id: int, name: string}>}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $this->oversees($user);
        abort_unless($all || $this->access->can($user, 'petty-cash'), 403);
        $floats = PettyCashFloat::with(['cash', 'custodian', 'replenishFrom'])->when(! $all, fn ($q) => $q->where('custodian_id', $user->id))->orderBy('id')->get();
        $manage = $this->access->can($user, 'manage');

        return response()->json([
            'data' => $floats->map(fn (PettyCashFloat $f) => $this->floatRow($f, $user))->values(),
            'can_manage' => $manage,
            'cash_accounts' => $manage ? CashBankAccount::where('is_active', true)->whereNotNull('account_id')->orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'kind' => (string) $b->kind])->values() : [],
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
        ]);
    }

    /**
     * Users to choose as custodian.
     *
     * @response array{data: list<array{id: int, name: string}>}
     */
    #[QueryParameter('search', type: 'string')]
    public function users(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $s = trim((string) $request->query('search'));
        $users = User::where('is_active', true)->when($s, fn ($q) => $q->where(fn ($q) => $q->where('username', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
            ->orWhere('full_name', 'like', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('fname', 'like', "%{$s}%")->orWhere('lname', 'like', "%{$s}%"))))
            ->with('employee')->limit(20)->get();

        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->display_name])->values()]);
    }

    /** @response array{data: array{id: int}} */
    public function storeFloat(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'custodian_id' => ['required', 'integer', 'exists:users,id'],
            'float_amount' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'max_voucher' => ['nullable', 'numeric', 'min:1'],
            'replenish_from_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $f = $this->petty->saveFloat(null, $data);

        return response()->json(['data' => ['id' => $f->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updateFloat(Request $request, PettyCashFloat $float): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'custodian_id' => ['required', 'integer', 'exists:users,id'],
            'float_amount' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'max_voucher' => ['nullable', 'numeric', 'min:1'],
            'replenish_from_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $f = $this->petty->saveFloat($float, $data);

        return response()->json(['data' => ['id' => $f->id]]);
    }

    /**
     * A float with its vouchers and top-ups, newest first.
     *
     * @response array{data: array{float: array{id: int, cash_bank_account_id: int, name: string|null, custodian_id: int, custodian: string|null, float_amount: float, max_voucher: float|null, replenish_from_id: int|null, replenish_from: string|null, is_active: bool, balance: float, available: float, pending: int, spent_since_top_up: float, mine: bool}, vouchers: list<array{id: int, number: string, date: string, payee: string, description: string, budget_line: string|null, amount: float, status: string, status_label: string, over_budget: bool, has_receipt: bool, created_by: string|null, waiting_for: string|null, decision_comment: string|null, topped_up: bool, can_withdraw: bool}>, replenishments: list<array{id: int, number: string, date: string, amount: float, from: string|null, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, can_withdraw: bool}>, can: array{voucher: bool, top_up: bool}}}
     */
    public function show(Request $request, PettyCashFloat $float): JsonResponse
    {
        $user = $request->user();
        $this->authorizeFloat($user, $float);
        $float->load(['cash', 'custodian', 'replenishFrom']);
        $vouchers = $float->vouchers()->with(['item.account', 'item.subActivity', 'item.activity', 'creator', 'workflow.steps'])->orderByDesc('date')->orderByDesc('id')->limit(500)->get();
        $tops = $float->replenishments()->with(['from', 'workflow.steps'])->orderByDesc('date')->orderByDesc('id')->limit(200)->get();
        $keeper = $float->custodian_id === $user->id || $this->access->can($user, 'manage');

        return response()->json(['data' => [
            'float' => $this->floatRow($float, $user),
            'vouchers' => $vouchers->map(fn (PettyCashVoucher $v) => ['id' => $v->id, 'number' => $v->number, 'date' => $v->date->toDateString(), 'payee' => $v->payee,
                'description' => $v->description, 'budget_line' => $v->item?->label(), 'amount' => $v->amount, 'status' => $v->status,
                'status_label' => PettyCashVoucher::STATUS_LABELS[$v->status] ?? $v->status, 'over_budget' => (bool) $v->over_budget, 'has_receipt' => (bool) $v->receipt_path,
                'created_by' => $v->creator?->display_name, 'waiting_for' => $v->status === 'pending' ? $v->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                'decision_comment' => $v->decision_comment, 'topped_up' => (bool) $v->petty_cash_replenishment_id, 'can_withdraw' => $v->status === 'pending' && $v->created_by === $user->id])->values(),
            'replenishments' => $tops->map(fn (PettyCashReplenishment $r) => ['id' => $r->id, 'number' => $r->number, 'date' => $r->date->toDateString(), 'amount' => $r->amount,
                'from' => $r->from?->name, 'status' => $r->status, 'status_label' => PettyCashReplenishment::STATUS_LABELS[$r->status] ?? $r->status,
                'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $r->decision_comment,
                'can_withdraw' => $r->status === 'pending' && $r->created_by === $user->id])->values(),
            'can' => ['voucher' => $keeper && $float->is_active, 'top_up' => $keeper && $float->is_active],
        ]]);
    }

    /**
     * Budget lines of a department in the current approved budget, for a petty cash voucher.
     *
     * @response array{data: list<array{id: int, label: string, available: float}>}
     */
    #[QueryParameter('department_id', type: 'integer', required: true)]
    public function budgetLines(Request $request, BudgetService $budgets): JsonResponse
    {
        abort_unless($this->oversees($request->user()) || $this->access->can($request->user(), 'petty-cash'), 403);
        $budget = Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->first();
        if (! $budget) {
            return response()->json(['data' => []]);
        }
        $items = BudgetItem::with(['account', 'subActivity', 'activity'])->where('budget_id', $budget->id)->where('department_id', (int) $request->query('department_id'))
            ->whereHas('account', fn ($q) => $q->where('type', 'expense'))->orderBy('id')->get();
        $figures = $budgets->figures($items);

        return response()->json(['data' => $items->map(fn ($i) => ['id' => $i->id, 'label' => $i->label(), 'available' => $figures[$i->id]['available']])->values()]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function storeVoucher(Request $request, PettyCashFloat $float): JsonResponse
    {
        $this->authorizeKeeper($request->user(), $float);
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'payee' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:255'],
            'budget_item_id' => ['required', 'integer', 'exists:budget_items,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'receipt' => ['nullable', ...array_slice(PaymentFiles::RULES, 1)],
        ]);
        $v = $this->petty->createVoucher($float, $data, $request->file('receipt'), $request->user());

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'status' => $v->status]], 201);
    }

    public function receipt(Request $request, PettyCashVoucher $voucher): StreamedResponse
    {
        $this->authorizeFloat($request->user(), $voucher->float);
        abort_unless((bool) $voucher->receipt_path, 404);

        return PaymentFiles::response($voucher->receipt_path, (string) $voucher->receipt_name);
    }

    /** @response array{message: string} */
    public function withdrawVoucher(Request $request, PettyCashVoucher $voucher): JsonResponse
    {
        $this->petty->withdrawVoucher($voucher, $request->user());

        return response()->json(['message' => 'Voucher withdrawn.']);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function replenish(Request $request, PettyCashFloat $float): JsonResponse
    {
        $this->authorizeKeeper($request->user(), $float);
        $data = $request->validate([
            'date' => ['required', 'date'],
            /** Leave empty to bring the float back to its full amount. */
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'from_account_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
        ]);
        $r = $this->petty->replenish($float, $data, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /** @response array{message: string} */
    public function withdrawReplenishment(Request $request, PettyCashReplenishment $replenishment): JsonResponse
    {
        $this->petty->withdrawReplenishment($replenishment, $request->user());

        return response()->json(['message' => 'Top-up withdrawn.']);
    }

    private function floatRow(PettyCashFloat $f, User $user): array
    {
        $balance = $this->petty->balance($f);

        return ['id' => $f->id, 'cash_bank_account_id' => $f->cash_bank_account_id, 'name' => $f->cash?->name, 'custodian_id' => $f->custodian_id, 'custodian' => $f->custodian?->display_name,
            'float_amount' => $f->float_amount, 'max_voucher' => $f->max_voucher, 'replenish_from_id' => $f->replenish_from_id, 'replenish_from' => $f->replenishFrom?->name,
            'is_active' => (bool) $f->is_active, 'balance' => $balance, 'available' => $this->petty->available($f), 'pending' => $f->vouchers()->where('status', 'pending')->count(),
            'spent_since_top_up' => round((float) $f->vouchers()->where('status', 'approved')->whereNull('petty_cash_replenishment_id')->sum('amount'), 2), 'mine' => $f->custodian_id === $user->id];
    }

    private function oversees(User $user): bool
    {
        return $this->access->finance($user) || $this->access->cashier($user) || $this->access->can($user, 'view');
    }

    private function authorizeFloat(User $user, PettyCashFloat $float): void
    {
        abort_unless($float->custodian_id === $user->id || $this->oversees($user), 403);
    }

    private function authorizeKeeper(User $user, PettyCashFloat $float): void
    {
        abort_unless($float->custodian_id === $user->id || $this->access->can($user, 'manage'), 403, 'Only the custodian of this petty cash can do this.');
    }
}
