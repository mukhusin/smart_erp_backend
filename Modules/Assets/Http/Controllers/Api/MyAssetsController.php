<?php

namespace Modules\Assets\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assets\Actions\ManageAssetReports;
use Modules\Assets\Http\Requests\AssetSelfReportRequest;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetReport;
use Modules\Assets\Models\AssetSetting;

/**
 * @tags Self service: my assets
 */
class MyAssetsController extends Controller
{
    public function __construct(private readonly ManageAssetReports $actions) {}

    /**
     * Assets I hold, and those of departments I head, with my open reports.
     *
     * @response array{data: list<array{id: int, number: string, name: string, category: string|null, serial_number: string|null, tag: string|null, department: string|null, mine: bool, location: string|null, condition: string, condition_label: string, status_label: string, since: string|null, confirmed: bool, open_report: array{type: string, type_label: string, status_label: string}|null}>, confirmation_due: bool, year: int, reports: list<array{id: int, asset: string, type_label: string, description: string|null, status_label: string, response: string|null, created_at: string}>}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $assets = $this->actions->heldBy($user)->load('assignments');
        $open = AssetReport::whereIn('asset_id', $assets->pluck('id'))->whereIn('status', ['open', 'repair'])->get()->keyBy('asset_id');
        $month = AssetSetting::current()->confirmation_month;
        $employee = $user->employee?->id;

        return response()->json([
            'data' => $assets->map(fn (Asset $a) => ['id' => $a->id, 'number' => $a->number, 'name' => $a->name, 'category' => $a->category?->name, 'serial_number' => $a->serial_number, 'tag' => $a->tag,
                'department' => $a->department?->name, 'mine' => $a->employee_id === $employee, 'location' => $a->location, 'condition' => $a->condition,
                'condition_label' => Asset::CONDITIONS[$a->condition] ?? $a->condition, 'status_label' => Asset::STATUS_LABELS[$a->status] ?? $a->status,
                'since' => $a->assignments->firstWhere('to_date', null)?->from_date?->toDateString(), 'confirmed' => (int) $a->confirmed_year === (int) today()->year,
                'open_report' => isset($open[$a->id]) ? ['type' => $open[$a->id]->type, 'type_label' => AssetReport::TYPES[$open[$a->id]->type], 'status_label' => AssetReport::STATUS_LABELS[$open[$a->id]->status]] : null])->values(),
            'confirmation_due' => $month !== null && (int) today()->month >= (int) $month && $assets->contains(fn ($a) => (int) $a->confirmed_year !== (int) today()->year),
            'year' => (int) today()->year,
            'reports' => AssetReport::with('asset')->where('reported_by', $user->id)->orderByDesc('id')->limit(50)->get()->map(fn ($r) => ['id' => $r->id, 'asset' => "{$r->asset->number} {$r->asset->name}",
                'type_label' => AssetReport::TYPES[$r->type], 'description' => $r->description, 'status_label' => AssetReport::STATUS_LABELS[$r->status] ?? $r->status, 'response' => $r->response,
                'created_at' => $r->created_at->toIso8601String()])->values(),
        ]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function report(AssetSelfReportRequest $request, Asset $asset): JsonResponse
    {
        $r = $this->actions->report($asset, collect($request->validated())->except('photo')->all(), $request->file('photo'), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]]);
    }

    /** @response array{data: array{confirmed: int}} */
    public function confirm(Request $request): JsonResponse
    {
        return response()->json(['data' => ['confirmed' => $this->actions->confirm($request->user())]]);
    }
}
