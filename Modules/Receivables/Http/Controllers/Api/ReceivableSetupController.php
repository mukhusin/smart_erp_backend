<?php

namespace Modules\Receivables\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Receivables\Http\Requests\ReceivableSettingsRequest;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Receipt;
use Modules\Receivables\Models\ReceivableSetting;
use Modules\Receivables\Support\ReceivableAccess;

/**
 * @tags Receivables: setup
 */
class ReceivableSetupController extends Controller
{
    public function __construct(private readonly ReceivableAccess $access) {}

    /** @response array{data: array{receivable_account_id: int|null, default_terms_days: int}, accounts: list<array{id: int, code: string, name: string}>} */
    public function settings(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $s = ReceivableSetting::current();

        return response()->json([
            'data' => ['receivable_account_id' => $s->receivable_account_id, 'default_terms_days' => (int) $s->default_terms_days],
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->where('type', 'asset')->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
        ]);
    }

    /** @response array{data: array{receivable_account_id: int|null, default_terms_days: int}, accounts: list<array{id: int, code: string, name: string}>} */
    public function updateSettings(ReceivableSettingsRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        ReceivableSetting::current()->fill($request->validated())->save();

        return $this->settings($request);
    }

    /**
     * Choice lists for billing forms: accounts, departments, revenue budget lines, bank accounts, customer kinds, receipt methods.
     *
     * @response array{data: array{accounts: list<array{id: int, code: string, name: string, type: string}>, departments: list<array{id: int, name: string}>, budget_lines: list<array{id: int, label: string, account_id: int, department_id: int}>, banks: list<array{id: int, name: string}>, kinds: list<array{value: string, label: string}>, methods: list<array{value: string, label: string}>, default_terms_days: int}}
     */
    public function lookups(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $budget = Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->first();
        $items = $budget ? BudgetItem::with(['account', 'subActivity', 'activity'])->where('budget_id', $budget->id)->whereHas('account', fn ($q) => $q->whereIn('type', ['revenue', 'liability', 'equity']))->get() : collect();

        return response()->json(['data' => [
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->whereIn('type', ['revenue', 'liability', 'equity', 'asset'])->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'budget_lines' => $items->map(fn ($i) => ['id' => $i->id, 'label' => $i->label(), 'account_id' => (int) $i->account_id, 'department_id' => (int) $i->department_id])->values(),
            'banks' => CashBankAccount::where('is_active', true)->orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'kinds' => collect(Customer::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'methods' => collect(Receipt::METHODS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'default_terms_days' => (int) ReceivableSetting::current()->default_terms_days,
        ]]);
    }
}
