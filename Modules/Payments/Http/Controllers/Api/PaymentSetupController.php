<?php

namespace Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Hr\Support\EmployeeScope;
use Modules\Payments\Actions\ManageLists;
use Modules\Payments\Http\Requests\PaymentSettingsRequest;
use Modules\Payments\Models\PaymentRequestPayee;
use Modules\Payments\Models\PaymentSetting;
use Modules\Payments\Support\PaymentAccess;

/**
 * @tags Payments: setup
 */
class PaymentSetupController extends Controller
{
    public function __construct(private readonly PaymentAccess $access) {}

    /** @response array{data: array{payable_account_id: int|null, withholding_account_id: int|null, require_attachment: bool}, accounts: list<array{id: int, code: string, name: string}>} */
    public function settings(Request $request): JsonResponse
    {
        abort_unless($this->access->finance($request->user()) || $this->access->cashier($request->user()), 403);
        $s = PaymentSetting::current();

        return response()->json([
            'data' => ['payable_account_id' => $s->payable_account_id, 'withholding_account_id' => $s->withholding_account_id, 'require_attachment' => (bool) $s->require_attachment],
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->where('type', 'liability')->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
        ]);
    }

    /** @response array{data: array{payable_account_id: int|null, withholding_account_id: int|null, require_attachment: bool}, accounts: list<array{id: int, code: string, name: string}>} */
    public function updateSettings(PaymentSettingsRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        PaymentSetting::current()->fill($request->validated())->save();

        return $this->settings($request);
    }

    /**
     * What the payment forms need: departments the user can request for, bank accounts with balance, choice lists.
     *
     * @response array{data: array{departments: list<array{id: int, name: string}>, banks: list<array{id: int, name: string, kind: string, balance: float|null}>, methods: list<array{value: string, label: string}>, kinds: list<array{value: string, label: string}>, require_attachment: bool, budget_control: string}}
     */
    public function lookups(Request $request, EmployeeScope $scope, ManageLists $lists, BudgetService $budgets): JsonResponse
    {
        $user = $request->user();
        $ids = null;
        if (! $this->access->can($user, 'manage')) {
            $rules = $this->access->can($user, 'request') ? $scope->rules($user) : ['departments' => []];
            $ids = $rules === null ? null : array_values(array_unique(array_filter([...$rules['departments'], $this->access->ownDepartment($user)])));
        }
        $money = $this->access->finance($user) || $this->access->cashier($user);

        return response()->json(['data' => [
            'departments' => Department::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name'])
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'banks' => CashBankAccount::where('is_active', true)->orderBy('name')->get()
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'kind' => (string) $b->kind, 'balance' => $money ? $lists->balance($b) : null])->values(),
            'methods' => collect(PaymentRequestPayee::METHODS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'kinds' => collect(PaymentRequestPayee::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'require_attachment' => (bool) PaymentSetting::current()->require_attachment,
            'budget_control' => $budgets->mode(),
        ]]);
    }

    /**
     * Budget lines of a department in the current approved budget, with what is still available.
     *
     * @response array{data: list<array{id: int, label: string, account: string|null, activity: string|null, revised: float, available: float}>, budget: array{id: int, title: string}|null}
     */
    #[QueryParameter('department_id', type: 'integer', required: true)]
    public function budgetLines(Request $request, BudgetService $budgets): JsonResponse
    {
        $department = (int) $request->query('department_id');
        abort_unless($this->access->canRequest($request->user(), $department) || $this->access->canView($request->user(), $department), 403);
        $budget = Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->first();
        if (! $budget) {
            return response()->json(['data' => [], 'budget' => null]);
        }
        $items = BudgetItem::with(['account', 'subActivity', 'activity'])->where('budget_id', $budget->id)->where('department_id', $department)->orderBy('id')->get();
        $figures = $budgets->figures($items);

        return response()->json([
            'data' => $items->map(fn (BudgetItem $i) => ['id' => $i->id, 'label' => $i->label(), 'account' => $i->account ? "{$i->account->code} {$i->account->name}" : null,
                'activity' => $i->activity?->title, 'revised' => $figures[$i->id]['revised'], 'available' => $figures[$i->id]['available']])->values(),
            'budget' => ['id' => $budget->id, 'title' => $budget->title],
        ]);
    }
}
