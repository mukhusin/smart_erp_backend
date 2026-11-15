<?php

namespace Modules\Hr\Http\Controllers\Api\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceActivityCount;
use App\Models\AllowanceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Actions\ManageActivityCounts;
use Modules\Hr\Actions\ManageAllowances;
use Modules\Hr\Support\ActivitySource;

/**
 * Daily counts of activity allowances (e.g. theatre cases): one number a day, times the rate, for everyone the rate covers.
 *
 * @tags Allowance: Activity counts
 */
class ActivityCountController extends Controller
{
    public function __construct(private readonly ManageActivityCounts $counts, private readonly ManageAllowances $allowances) {}

    /**
     * The counts of a category for a period, one row per day (days without a count included).
     *
     * @response array{data: list<array{date: string, id: int|null, count: float|null, status: string, source: string|null, error: string|null, entered_by: string|null, updated_at: string|null}>, meta: array{categories: list<array{id: int, name: string, source: string, source_label: string}>, category_id: int|null}}
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['category_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $categories = AllowanceCategory::where('generation', 'activity')->where('duration', 'case')->orderByDesc('is_active')->orderBy('category')->get();
        $category = $categories->firstWhere('id', (int) ($f['category_id'] ?? 0)) ?? $categories->first();
        $to = Carbon::parse($f['to'] ?? now())->startOfDay()->min(now()->startOfDay());
        $from = Carbon::parse($f['from'] ?? $to->copy()->startOfMonth())->startOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose at most a year.');

        $rows = $category ? AllowanceActivityCount::with('enteredBy')->where('allowance_category_id', $category->id)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->keyBy(fn ($c) => $c->date->toDateString()) : collect();
        $days = [];
        for ($d = $to->copy(); $category && $d->gte($from); $d->subDay()) {
            $c = $rows->get($d->toDateString());
            $days[] = [
                'date' => $d->toDateString(), 'id' => $c?->id, 'count' => $c?->count, 'status' => $c?->status ?? 'missing', 'source' => $c?->source,
                'error' => $c?->error, 'entered_by' => $c?->enteredBy?->display_name, 'updated_at' => $c?->updated_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $days, 'meta' => [
            'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->category, 'source' => $c->activity_source ?? 'manual',
                'source_label' => AllowanceCategory::SOURCES[$c->activity_source ?? 'manual']])->values(),
            'category_id' => $category?->id,
        ]]);
    }

    /**
     * Enter or correct the count of a day by hand (kept even when the source is fetched). Allowances of the day are worked out again.
     *
     * @response array{data: array{date: string, count: float}}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['category_id' => ['required', 'integer'], 'date' => ['required', 'date', 'before_or_equal:today'], 'count' => ['required', 'numeric', 'min:0', 'max:100000']]);
        $row = $this->counts->enter($this->category($d['category_id']), Carbon::parse($d['date'])->startOfDay(), (float) $d['count'], $request->user());

        return response()->json(['data' => ['date' => $row->date->toDateString(), 'count' => $row->count]]);
    }

    /**
     * Remove a day's count. A day without a count keeps the allowances already worked out; fetch again to replace it.
     */
    public function destroy(AllowanceActivityCount $count): JsonResponse
    {
        $count->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Fetch the counts from the source for a period (at most 31 days) and work out the allowances again.
     *
     * @response array{data: array{fetched: int, failed: int, kept: int}}
     */
    public function fetch(Request $request): JsonResponse
    {
        $d = $request->validate(['category_id' => ['required', 'integer'], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:today']]);
        $category = $this->category($d['category_id']);
        if ($category->activity_source !== 'rest') {
            throw ValidationException::withMessages(['category_id' => "{$category->category} counts are entered by hand."]);
        }
        [$from, $to] = [Carbon::parse($d['from'])->startOfDay(), Carbon::parse($d['to'])->startOfDay()];
        abort_if($from->diffInDays($to) > 30, 422, 'Fetch at most 31 days at a time.');
        $fetched = $failed = $kept = 0;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $row = $this->counts->fetch($category, $day->copy());
            $row === null ? $kept++ : ($row->status === 'ok' ? $fetched++ : $failed++);
        }
        $this->allowances->generate($from, $to);

        return response()->json(['data' => ['fetched' => $fetched, 'failed' => $failed, 'kept' => $kept]]);
    }

    /**
     * Try the saved source for a day without saving anything.
     *
     * @response array{data: array{count: float|null, error: string|null, raw: string|null}}
     */
    public function test(Request $request, AllowanceCategory $category, ActivitySource $source): JsonResponse
    {
        $d = $request->validate(['date' => ['nullable', 'date']]);
        abort_unless($category->activity() && $category->activity_source === 'rest', 422, 'This category is not fetched from another system.');
        $r = $source->fetch($category, $d['date'] ?? now()->subDay());

        return response()->json(['data' => ['count' => $r['count'], 'error' => $r['error'], 'raw' => $r['raw'] ? Str::limit($r['raw'], 2000) : null]]);
    }

    private function category(int $id): AllowanceCategory
    {
        $category = AllowanceCategory::where('is_active', true)->find($id);
        if (! $category?->activity()) {
            throw ValidationException::withMessages(['category_id' => 'Choose an active allowance worked out from a daily activity count.']);
        }

        return $category;
    }
}
