<?php

namespace Modules\Procurement\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetItem;

final class BudgetLines
{
    /** Budget lines of one approved budget and one department; returns them keyed by id. */
    public static function check(array $ids, int $departmentId): Collection
    {
        $items = BudgetItem::with('budget')->whereIn('id', array_unique($ids))->get()->keyBy('id');
        if ($items->count() !== count(array_unique($ids)) || $items->pluck('budget_id')->unique()->count() !== 1 || $items->first()->budget?->status !== 'approved') {
            throw ValidationException::withMessages(['lines' => 'Choose budget lines of one approved budget.']);
        }
        if ($items->contains(fn (BudgetItem $i) => $i->department_id !== $departmentId)) {
            throw ValidationException::withMessages(['lines' => 'Every budget line must belong to the department of the requisition.']);
        }

        return $items;
    }

    /** @return array<int, float> budget item id => total amount */
    public static function amounts(iterable $lines, string $field = 'amount'): array
    {
        $out = [];
        foreach ($lines as $l) {
            $id = (int) (is_array($l) ? $l['budget_item_id'] : $l->budget_item_id);
            $out[$id] = round(($out[$id] ?? 0) + (float) (is_array($l) ? $l[$field] : $l->{$field}), 2);
        }

        return $out;
    }
}
