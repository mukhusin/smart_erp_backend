<?php

namespace Modules\Receivables\Support;

use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Budgeting\Models\BudgetItem;

final class RevenueLines
{
    /** Checks each line's account (postable) and budget line (same account), returns account ids by line. */
    public static function check(array $lines, string $field = 'lines', bool $revenueOnly = true): void
    {
        $accounts = Account::whereIn('id', array_column($lines, 'account_id'))->get()->keyBy('id');
        $items = BudgetItem::whereIn('id', array_filter(array_column($lines, 'budget_item_id')))->get()->keyBy('id');
        foreach ($lines as $i => $l) {
            $account = $accounts[$l['account_id']] ?? null;
            if (! $account || ! $account->is_active || ! $account->is_postable || ($revenueOnly && $account->type !== 'revenue')) {
                throw ValidationException::withMessages(["{$field}.{$i}.account_id" => 'Line '.($i + 1).': choose an active '.($revenueOnly ? 'revenue ' : '').'account that takes postings.']);
            }
            if (! empty($l['budget_item_id'])) {
                $item = $items[$l['budget_item_id']] ?? null;
                if (! $item || $item->account_id !== $account->id) {
                    throw ValidationException::withMessages(["{$field}.{$i}.budget_item_id" => 'Line '.($i + 1).': the budget line must be for the same account.']);
                }
            }
        }
    }
}
