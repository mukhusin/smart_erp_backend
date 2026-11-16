<?php

namespace Modules\Budgeting\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;

class BudgetFigures
{
    public function __construct(private readonly BudgetService $service) {}

    public function items(Collection $items): Collection
    {
        $items = $items instanceof EloquentCollection ? $items : new EloquentCollection($items->all());
        $items->loadMissing(['account', 'department', 'activity', 'subActivity', 'fundSource', 'periods']);
        $figures = $this->service->figures($items);

        return $items->map(fn (BudgetItem $i) => $this->row($i, $figures[$i->id] ?? ['revised' => 0, 'released' => 0, 'unreleased' => 0, 'reserved' => 0, 'actual' => 0, 'committed' => 0, 'available' => 0]));
    }

    public function row(BudgetItem $i, array $f): array
    {
        return [
            'id' => $i->id, 'label' => $i->label(), 'department_id' => (int) $i->department_id, 'department' => $i->department?->name,
            'activity' => $i->activity ? ['id' => $i->activity->id, 'code' => $i->activity->code, 'title' => $i->activity->title] : null,
            'sub_activity' => $i->subActivity ? ['id' => $i->subActivity->id, 'code' => $i->subActivity->code, 'title' => $i->subActivity->title] : null,
            'account' => $i->account ? ['id' => $i->account->id, 'code' => $i->account->code, 'gfs_code' => $i->account->gfs_code, 'name' => $i->account->name, 'type' => $i->account->type] : null,
            'fund_source' => $i->fundSource ? ['id' => $i->fundSource->id, 'code' => $i->fundSource->code] : null,
            'description' => $i->description, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'unit_cost' => (float) $i->unit_cost, 'frequency' => (float) $i->frequency,
            'periods' => $i->periods->pluck('amount')->map(fn ($v) => (float) $v)->values()->all(),
            'original' => (float) $i->original_amount, 'revised' => $f['revised'], 'released' => $f['released'], 'unreleased' => $f['unreleased'], 'reserved' => $f['reserved'],
            'actual' => $f['actual'], 'committed' => $f['committed'], 'available' => $f['available'],
            'used_percent' => $f['revised'] > 0 ? round(($f['actual'] + $f['committed'] + $f['reserved']) / $f['revised'] * 100, 1) : null,
        ];
    }

    public function totals(Collection $rows): array
    {
        return ['original' => round($rows->sum('original'), 2), 'revised' => round($rows->sum('revised'), 2), 'released' => round($rows->sum('released'), 2),
            'reserved' => round($rows->sum('reserved'), 2), 'actual' => round($rows->sum('actual'), 2), 'committed' => round($rows->sum('committed'), 2), 'available' => round($rows->sum('available'), 2)];
    }
}
