<?php

namespace Modules\Budgeting\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Budgeting\Models\BudgetEntry;
use Modules\Budgeting\Models\BudgetItem;

class BudgetService
{
    public function mode(): string
    {
        return AccountingSetting::current()->budget_control ?: 'warn';
    }

    public function releases(): bool
    {
        return (AccountingSetting::current()->budget_release ?: 'period') === 'period';
    }

    /** @return array<int, array{revised: float, released: float, unreleased: float, reserved: float, committed: float, actual: float, available: float}> */
    public function figures(Collection $items): array
    {
        $ids = $items->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $sums = BudgetEntry::whereIn('budget_item_id', $ids)->groupBy('budget_item_id', 'balance')
            ->selectRaw('budget_item_id, balance, SUM(amount) as total')->get()->groupBy('budget_item_id');
        $release = $this->releases();
        $out = [];
        foreach ($ids as $id) {
            $b = ($sums[$id] ?? collect())->pluck('total', 'balance');
            $approved = round((float) ($b['approved'] ?? 0), 2);
            $released = $release ? round((float) ($b['released'] ?? 0), 2) : $approved;
            $reserved = round((float) ($b['reserved'] ?? 0), 2);
            $committed = round((float) ($b['committed'] ?? 0), 2);
            $spent = round((float) ($b['spent'] ?? 0), 2);
            $out[$id] = ['revised' => $approved, 'released' => $released, 'unreleased' => round($approved - $released, 2), 'reserved' => $reserved,
                'committed' => $committed, 'actual' => $spent, 'available' => round($released - $reserved - $committed - $spent, 2)];
        }

        return $out;
    }

    public function available(BudgetItem $item): float
    {
        return $this->figures(collect([$item]))[$item->id]['available'];
    }

    /** @return array{available: float, over: bool, mode: string} */
    public function check(BudgetItem $item, float $amount, ?Model $except = null): array
    {
        $available = $this->available($item) + ($except ? $this->heldBy($except, $item->id) : 0);

        return ['available' => round($available, 2), 'over' => round($amount, 2) > round($available, 2), 'mode' => $this->mode()];
    }

    public function record(int $itemId, string $balance, float $amount, ?Model $source = null, ?string $note = null, ?User $by = null): void
    {
        if (round($amount, 2) == 0) {
            return;
        }
        BudgetEntry::create(['budget_item_id' => $itemId, 'balance' => $balance, 'amount' => round($amount, 2), 'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(), 'note' => $note, 'user_id' => $by?->id ?? auth()->id()]);
    }

    /**
     * @param  array<int, float>  $amounts  budget item id => amount
     * @return list<array{item_id: int, label: string, asked: float, available: float}> items that are short (empty if none)
     */
    public function reserve(Model $source, array $amounts, bool $allowOver = false, ?User $by = null): array
    {
        return DB::transaction(function () use ($source, $amounts, $allowOver, $by) {
            $items = BudgetItem::whereIn('id', array_keys($amounts))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->release($source, $by);
            $figures = $this->figures($items->values());
            $short = [];
            foreach ($amounts as $id => $amount) {
                $available = $figures[$id]['available'] ?? 0;
                if (round($amount, 2) > round($available, 2)) {
                    $short[] = ['item_id' => (int) $id, 'label' => $items[$id]?->label() ?? 'Budget item', 'asked' => round($amount, 2), 'available' => $available];
                }
            }
            if ($short && ! $allowOver) {
                throw ValidationException::withMessages(['budget' => collect($short)->map(fn ($s) => "{$s['label']}: asked ".number_format($s['asked'], 2).', available '.number_format($s['available'], 2))->join('; ')]);
            }
            foreach ($amounts as $id => $amount) {
                $this->record((int) $id, 'reserved', (float) $amount, $source, 'Held', $by);
            }

            return $short;
        });
    }

    public function release(Model $source, ?User $by = null): void
    {
        foreach (['reserved', 'committed'] as $balance) {
            foreach ($this->bySource($source, $balance) as $itemId => $total) {
                $this->record($itemId, $balance, -$total, $source, 'Released', $by);
            }
        }
    }

    public function commit(Model $source, ?User $by = null): void
    {
        foreach ($this->bySource($source, 'reserved') as $itemId => $total) {
            $this->record($itemId, 'reserved', -$total, $source, 'Committed', $by);
            $this->record($itemId, 'committed', $total, $source, 'Committed', $by);
        }
    }

    public function spend(int $itemId, float $amount, ?Model $source, ?Model $journal = null, ?User $by = null): void
    {
        $this->record($itemId, 'spent', $amount, $journal ?? $source, 'Spent', $by);
        if (! $source || $amount <= 0) {
            return;
        }
        $left = $amount;
        foreach (['committed', 'reserved'] as $balance) {
            $held = $this->bySource($source, $balance)[$itemId] ?? 0;
            $take = min($left, $held);
            if ($take > 0) {
                $this->record($itemId, $balance, -$take, $source, 'Paid', $by);
                $left -= $take;
            }
        }
    }

    public function heldBy(Model $source, ?int $itemId = null): float
    {
        $held = $this->bySource($source, 'reserved');
        $committed = $this->bySource($source, 'committed');

        return $itemId === null ? array_sum($held) + array_sum($committed) : ($held[$itemId] ?? 0) + ($committed[$itemId] ?? 0);
    }

    /** @return array<int, float> item id => amount still on this balance for the source */
    private function bySource(Model $source, string $balance): array
    {
        return BudgetEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->where('balance', $balance)
            ->groupBy('budget_item_id')->selectRaw('budget_item_id, SUM(amount) as total')->pluck('total', 'budget_item_id')
            ->map(fn ($v) => round((float) $v, 2))->filter(fn ($v) => $v > 0)->all();
    }
}
