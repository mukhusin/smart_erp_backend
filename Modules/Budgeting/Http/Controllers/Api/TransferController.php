<?php

namespace Modules\Budgeting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Budgeting\Actions\ManageTransfers;
use Modules\Budgeting\Http\Requests\TransferRequest;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\BudgetTransfer;
use Modules\Budgeting\Support\BudgetAccess;

/**
 * @tags Budgeting
 */
class TransferController extends Controller
{
    public function __construct(private readonly BudgetAccess $access, private readonly ManageTransfers $transfers) {}

    /** @response array{data: list<array{id: int, kind: string, kind_label: string, from: string|null, from_department: string|null, to: string, to_department: string|null, amount: float, reason: string, status: string, status_label: string, requested_by: string|null, created_at: string, decision_comment: string|null, can_withdraw: bool}>} */
    public function index(Request $request, Budget $budget): JsonResponse
    {
        $user = $request->user();
        $departments = $this->access->departments($user);
        abort_unless($departments !== [], 403);
        $rows = BudgetTransfer::with(['fromItem.account', 'fromItem.department', 'fromItem.subActivity', 'fromItem.activity', 'toItem.account', 'toItem.department', 'toItem.subActivity', 'toItem.activity', 'requester'])
            ->where('budget_id', $budget->id)
            ->when($departments !== null, fn ($q) => $q->where(fn ($q) => $q->whereHas('toItem', fn ($i) => $i->whereIn('department_id', $departments))->orWhereHas('fromItem', fn ($i) => $i->whereIn('department_id', $departments))))
            ->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (BudgetTransfer $t) => [
            'id' => $t->id, 'kind' => $t->kind, 'kind_label' => BudgetTransfer::KINDS[$t->kind] ?? $t->kind,
            'from' => $t->fromItem?->label(), 'from_department' => $t->fromItem?->department?->name, 'to' => $t->toItem?->label() ?? '—', 'to_department' => $t->toItem?->department?->name,
            'amount' => (float) $t->amount, 'reason' => $t->reason, 'status' => $t->status, 'status_label' => BudgetTransfer::STATUS_LABELS[$t->status] ?? $t->status,
            'requested_by' => $t->requester?->display_name, 'created_at' => $t->created_at->toIso8601String(), 'decision_comment' => $t->decision_comment,
            'can_withdraw' => $t->status === 'pending' && $t->requested_by === $user->id,
        ])->values()]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function store(TransferRequest $request, Budget $budget): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $to = BudgetItem::where('budget_id', $budget->id)->findOrFail($data['to_item_id']);
        $from = ! empty($data['from_item_id']) ? BudgetItem::where('budget_id', $budget->id)->find($data['from_item_id']) : null;
        $allowed = $this->access->manages($user) || ($this->access->canPrepare($user, $to->department_id) && (! $from || $this->access->canPrepare($user, $from->department_id)) && $data['kind'] === 'transfer');
        abort_unless($allowed, 403, 'You can only move money between items of your departments; supplementary budget is requested by finance.');
        $t = $this->transfers->request($budget, $data, $user);

        return response()->json(['data' => ['id' => $t->id, 'status' => $t->status]], 201);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function withdraw(Request $request, Budget $budget, BudgetTransfer $transfer): JsonResponse
    {
        abort_unless($transfer->budget_id === $budget->id, 404);
        $t = $this->transfers->withdraw($transfer, $request->user());

        return response()->json(['data' => ['id' => $t->id, 'status' => $t->status]]);
    }
}
