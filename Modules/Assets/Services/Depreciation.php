<?php

namespace Modules\Assets\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\PostingService;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetSetting;
use Modules\Assets\Models\DepreciationRun;

/**
 * Straight-line depreciation at a daily rate: (book value at the basis date − residual value) spread over the days left
 * of the useful life, so part months are exact. Any change in value or life starts a new basis from the next day not
 * yet charged. Posted monthly, one journal per run with a line per category and department.
 */
class Depreciation
{
    public function __construct(private readonly PostingService $posting) {}

    public function startDate(Asset $asset): ?Carbon
    {
        $from = AssetSetting::current()->depreciation_start === 'acquisition' ? $asset->acquisition_date : $asset->in_use_date;

        return $from?->copy();
    }

    /** Sets the first basis once the asset is approved and (when the setting says so) in use. */
    public function begin(Asset $asset, ?Carbon $from = null): void
    {
        $asset->loadMissing('category');
        if (! $asset->depreciable() || $asset->dep_from) {
            return;
        }
        $start = $this->startDate($asset);
        if (! $start) {
            return;
        }
        $from ??= $start;
        $value = $asset->valueAt();
        $asset->update(['dep_from' => $from->toDateString(), 'dep_value' => $value['book_value'], 'dep_until' => $start->copy()->addMonthsNoOverflow((int) $asset->useful_life_months)->subDay()->toDateString(),
            'dep_charged_to' => $asset->dep_charged_to ?? $from->copy()->subDay()->toDateString()]);
    }

    /** After a change in value or life: the remaining book value is spread over the days still left. */
    public function rebase(Asset $asset): void
    {
        $asset->refresh();
        if (! $asset->dep_from) {
            $this->begin($asset);

            return;
        }
        $from = $asset->dep_charged_to ? $asset->dep_charged_to->copy()->addDay() : $asset->dep_from;
        $asset->update(['dep_from' => $from->toDateString(), 'dep_value' => $asset->valueAt()['book_value']]);
    }

    /** Depreciation not yet charged, up to and including the date. */
    public function due(Asset $asset, string $to): float
    {
        if (! $asset->dep_from || ! $asset->dep_until || ! $asset->depreciable()) {
            return 0.0;
        }
        $start = max($asset->dep_from->copy(), $asset->dep_charged_to ? $asset->dep_charged_to->copy()->addDay() : $asset->dep_from->copy());
        $end = min(Carbon::parse($to), $asset->dep_until->copy());
        if ($end->lt($start)) {
            return 0.0;
        }
        $total = $asset->dep_from->diffInDays($asset->dep_until) + 1;
        $rate = max(0, $asset->dep_value - $asset->residual_value) / max($total, 1);
        $amount = round($rate * ($start->diffInDays($end) + 1), 2);
        $left = round($asset->valueAt()['book_value'] - $asset->residual_value, 2);
        if ($end->equalTo($asset->dep_until)) {
            $amount = $left;
        }

        return max(0.0, min($amount, $left));
    }

    public function lastRun(): ?DepreciationRun
    {
        return DepreciationRun::where('status', 'posted')->orderByDesc('period_end')->first();
    }

    public function nextPeriod(): Carbon
    {
        $last = $this->lastRun();

        return $last ? $last->period_end->copy()->addDay()->endOfMonth()->startOfDay() : today()->subMonthNoOverflow()->endOfMonth()->startOfDay();
    }

    public function run(string $periodEnd, ?User $by): DepreciationRun
    {
        $end = Carbon::parse($periodEnd)->endOfMonth()->startOfDay();
        $last = $this->lastRun();
        if ($last && ! $end->equalTo($last->period_end->copy()->addDay()->endOfMonth()->startOfDay())) {
            throw ValidationException::withMessages(['period_end' => 'Run '.$last->period_end->copy()->addDay()->format('F Y').' first; months are run in order.']);
        }
        if (DepreciationRun::where('status', 'posted')->whereDate('period_end', $end)->exists()) {
            throw ValidationException::withMessages(['period_end' => 'Depreciation for '.$end->format('F Y').' is already posted.']);
        }
        if ($end->gt(today()->endOfMonth())) {
            throw ValidationException::withMessages(['period_end' => 'A month can be run only once it has started.']);
        }
        DepreciationRun::where('status', 'failed')->whereDate('period_end', $end)->delete();
        try {
            return DB::transaction(function () use ($end, $by) {
                $run = DepreciationRun::create(['period_end' => $end->toDateString(), 'status' => 'posted', 'run_by' => $by?->id]);
                $assets = Asset::with('category')->whereIn('status', Asset::ON_BOOKS)->whereNotNull('dep_from')->get();
                $lines = [];
                $count = 0;
                foreach ($assets as $asset) {
                    $amount = $this->due($asset, $end->toDateString());
                    if ($amount <= 0) {
                        continue;
                    }
                    $this->charge($asset, $amount, $end->toDateString(), $run, 'Depreciation '.$end->format('M Y'));
                    $c = $asset->category;
                    if (! $c->depreciation_account_id || ! $c->accumulated_account_id) {
                        throw ValidationException::withMessages(['category' => "Category {$c->name} has no depreciation accounts."]);
                    }
                    $key = $c->id.'|'.($asset->department_id ?? 0).'|'.($asset->fund_source_id ?? 0);
                    $lines[$key] ??= ['category' => $c, 'department_id' => $asset->department_id, 'fund_source_id' => $asset->fund_source_id, 'amount' => 0.0];
                    $lines[$key]['amount'] = round($lines[$key]['amount'] + $amount, 2);
                    $count++;
                }
                $total = round(collect($lines)->sum('amount'), 2);
                $journal = null;
                if ($total > 0) {
                    $rows = [];
                    foreach ($lines as $l) {
                        $rows[] = ['account_id' => $l['category']->depreciation_account_id, 'debit' => $l['amount'], 'description' => "Depreciation {$l['category']->name}", 'department_id' => $l['department_id'], 'fund_source_id' => $l['fund_source_id']];
                        $rows[] = ['account_id' => $l['category']->accumulated_account_id, 'credit' => $l['amount'], 'description' => "Accumulated depreciation {$l['category']->name}", 'fund_source_id' => $l['fund_source_id']];
                    }
                    $journal = $this->posting->post($end->toDateString(), 'Depreciation '.$end->format('F Y'), $rows, $by ?? User::find($run->run_by) ?? User::first(), 'depreciation', $run);
                    \Modules\Assets\Models\AssetEntry::where('source_type', $run->getMorphClass())->where('source_id', $run->id)->update(['journal_entry_id' => $journal->id]);
                }
                $run->update(['assets' => $count, 'total' => $total, 'journal_entry_id' => $journal?->id]);

                return $run;
            });
        } catch (ValidationException $e) {
            DepreciationRun::create(['period_end' => $end->toDateString(), 'status' => 'failed', 'run_by' => $by?->id, 'error' => collect($e->errors())->flatten()->join(' ')]);
            throw $e;
        }
    }

    public function charge(Asset $asset, float $amount, string $date, $source, string $description): void
    {
        $asset->entries()->create(['date' => $date, 'kind' => 'depreciation', 'depreciation' => $amount, 'description' => $description,
            'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey()]);
        $asset->update(['dep_charged_to' => $date]);
    }
}
