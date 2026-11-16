<?php

namespace Modules\Budgeting\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\BudgetTransfer;
use Modules\Budgeting\Services\BudgetService;
use Modules\Budgeting\Workflow\BudgetTransferProcess;
use Modules\Core\Notifications\Notice;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageTransfers
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets) {}

    public function request(Budget $budget, array $data, User $by): BudgetTransfer
    {
        if ($budget->status !== 'approved') {
            throw ValidationException::withMessages(['budget' => 'Transfers are made on an approved budget.']);
        }
        $to = BudgetItem::where('budget_id', $budget->id)->findOrFail($data['to_item_id']);
        $from = $data['kind'] === 'transfer' ? BudgetItem::where('budget_id', $budget->id)->find($data['from_item_id'] ?? 0) : null;
        if ($data['kind'] === 'transfer') {
            if (! $from || $from->id === $to->id) {
                throw ValidationException::withMessages(['from_item_id' => 'Choose a different budget item to take the money from.']);
            }
            $check = $this->budgets->check($from->load('account'), (float) $data['amount']);
            if ($check['over']) {
                throw ValidationException::withMessages(['amount' => 'Only '.number_format($check['available'], 2).' is left on the item you take from.']);
            }
        }

        return DB::transaction(function () use ($budget, $data, $by, $from, $to) {
            $t = BudgetTransfer::create(['budget_id' => $budget->id, 'kind' => $data['kind'], 'from_item_id' => $from?->id, 'to_item_id' => $to->id,
                'amount' => round((float) $data['amount'], 2), 'reason' => $data['reason'], 'status' => 'pending', 'requested_by' => $by->id]);
            if ($from) {
                $this->budgets->reserve($t, [$from->id => (float) $data['amount']]);
            }
            if (! $this->workflow->start(BudgetTransferProcess::KEY, $t, $by)) {
                $this->apply($t);
            }

            return $t->refresh();
        });
    }

    public function apply(BudgetTransfer $t): void
    {
        DB::transaction(function () use ($t) {
            $this->budgets->release($t);
            if ($t->fromItem) {
                $t->fromItem->decrement('revised_amount', $t->amount);
            }
            $t->toItem->increment('revised_amount', $t->amount);
            $t->update(['status' => 'approved', 'decided_at' => now()]);
        });
        $t->requester?->notify(new Notice('Budget transfer approved', number_format($t->amount, 2).' moved to '.$t->toItem->label().'.', "/budgeting/budgets/{$t->budget_id}/transfers", 'wallet2'));
    }

    public function rejected(BudgetTransfer $t, ?string $comment, bool $withdrawn): void
    {
        $this->budgets->release($t);
        $t->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $t->requester?->notify(new Notice('Budget transfer not approved', "Your transfer to {$t->toItem->label()} was not approved: {$comment}", "/budgeting/budgets/{$t->budget_id}/transfers", 'wallet2'));
        }
    }

    public function withdraw(BudgetTransfer $t, User $by): BudgetTransfer
    {
        if ($t->status !== 'pending' || $t->requested_by !== $by->id) {
            throw ValidationException::withMessages(['transfer' => 'Only your transfer waiting for approval can be withdrawn.']);
        }
        $instance = $t->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($t, null, true);

        return $t->refresh();
    }
}
