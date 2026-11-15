<?php

namespace Modules\Hr\Actions;

use App\Models\AllowanceActivityCount;
use App\Models\AllowanceCategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Hr\Support\ActivitySource;

/** Daily counts of activity allowances (e.g. theatre cases): fetched from the configured source or entered by hand. */
class ManageActivityCounts
{
    public function __construct(private readonly ActivitySource $source, private readonly ManageAllowances $allowances) {}

    /** Fetch counts of every REST-fed category for the last days. Counts entered by hand are kept. @return array{fetched: int, failed: int} */
    public function fetchRecent(int $days = 3): array
    {
        $fetched = $failed = 0;
        $categories = AllowanceCategory::where('is_active', true)->where('generation', 'activity')->where('duration', 'case')->where('activity_source', 'rest')->get();
        foreach ($categories as $category) {
            for ($d = $days; $d >= 0; $d--) {
                $row = $this->fetch($category, now()->subDays($d));
                $row?->status === 'ok' ? $fetched++ : ($row ? $failed++ : null);
            }
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }

    public function fetch(AllowanceCategory $category, Carbon $date): ?AllowanceActivityCount
    {
        $existing = AllowanceActivityCount::where('allowance_category_id', $category->id)->whereDate('date', $date)->first();
        if ($existing?->source === 'manual') {
            return null;
        }
        $r = $this->source->fetch($category, $date);
        if ($r['error']) {
            Log::warning('Activity count fetch failed', ['category' => $category->id, 'date' => $date->toDateString(), 'error' => $r['error']]);
            if ($existing?->status === 'ok') {
                return $existing;
            }
        }

        return $this->put($category, $date, ['count' => $r['count'], 'source' => 'rest', 'status' => $r['error'] ? 'failed' : 'ok', 'error' => $r['error'], 'raw' => $r['raw'], 'entered_by' => null], $existing);
    }

    public function enter(AllowanceCategory $category, Carbon $date, float $count, User $by): AllowanceActivityCount
    {
        $row = $this->put($category, $date, ['count' => $count, 'source' => 'manual', 'status' => 'ok', 'error' => null, 'raw' => null, 'entered_by' => $by->id]);
        $this->allowances->generate($date, $date);

        return $row;
    }

    private function put(AllowanceCategory $category, Carbon $date, array $values, ?AllowanceActivityCount $row = null): AllowanceActivityCount
    {
        $row ??= AllowanceActivityCount::where('allowance_category_id', $category->id)->whereDate('date', $date)->first();
        if ($row) {
            $row->update($values);

            return $row;
        }

        return AllowanceActivityCount::create($values + ['allowance_category_id' => $category->id, 'date' => $date->toDateString()]);
    }
}
