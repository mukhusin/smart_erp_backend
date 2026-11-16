<?php

namespace Modules\Budgeting\Services;

use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetItem;

class BudgetService
{
    public function mode(): string
    {
        return AccountingSetting::current()->budget_control ?: 'warn';
    }

    /** @return array<int, array{revised: float, actual: float, committed: float, available: float}> */
    public function figures(Collection $items): array
    {
        $ids = $items->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $actual = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->whereIn('l.budget_item_id', $ids)
            ->groupBy('l.budget_item_id')->selectRaw('l.budget_item_id as id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('id');
        $committed = BudgetCommitment::whereIn('budget_item_id', $ids)->where('status', 'reserved')->groupBy('budget_item_id')
            ->selectRaw('budget_item_id, SUM(amount) as total')->pluck('total', 'budget_item_id');
        $out = [];
        foreach ($items as $item) {
            $a = $actual[$item->id] ?? null;
            $spent = $a ? ($item->account?->type === 'revenue' ? (float) $a->c - (float) $a->d : (float) $a->d - (float) $a->c) : 0.0;
            $c = (float) ($committed[$item->id] ?? 0);
            $out[$item->id] = ['revised' => round((float) $item->revised_amount, 2), 'actual' => round($spent, 2), 'committed' => round($c, 2),
                'available' => round((float) $item->revised_amount - $spent - $c, 2)];
        }

        return $out;
    }

    public function available(BudgetItem $item): float
    {
        return $this->figures(collect([$item->loadMissing('account')]))[$item->id]['available'];
    }

    /** @return array{available: float, over: bool, mode: string} */
    public function check(BudgetItem $item, float $amount, ?Model $except = null): array
    {
        $available = $this->available($item);
        if ($except) {
            $available += (float) BudgetCommitment::where('budget_item_id', $item->id)->where('status', 'reserved')
                ->where('source_type', $except->getMorphClass())->where('source_id', $except->getKey())->sum('amount');
        }

        return ['available' => round($available, 2), 'over' => round($amount, 2) > round($available, 2), 'mode' => $this->mode()];
    }

    /** @param array<int, float> $amounts budget item id => amount */
    public function reserve(Model $source, array $amounts): void
    {
        $this->release($source);
        foreach ($amounts as $itemId => $amount) {
            if ($amount > 0) {
                BudgetCommitment::create(['budget_item_id' => $itemId, 'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'amount' => round($amount, 2), 'status' => 'reserved']);
            }
        }
    }

    public function release(Model $source): void
    {
        $this->sourceQuery($source)->where('status', 'reserved')->update(['status' => 'released']);
    }

    public function consume(Model $source): void
    {
        $this->sourceQuery($source)->where('status', 'reserved')->update(['status' => 'consumed']);
    }

    private function sourceQuery(Model $source)
    {
        return BudgetCommitment::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey());
    }
}
