<?php

namespace Modules\Assets\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assets\Http\Requests\AssetSettingsRequest;
use Modules\Assets\Http\Requests\DepreciationRunRequest;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetSetting;
use Modules\Assets\Models\DepreciationRun;
use Modules\Assets\Services\Depreciation;
use Modules\Assets\Support\AssetAccess;

/**
 * @tags Assets: depreciation and settings
 */
class DepreciationController extends Controller
{
    public function __construct(private readonly AssetAccess $access, private readonly Depreciation $depreciation) {}

    /**
     * Monthly depreciation runs and what the next one will charge.
     *
     * @response array{data: list<array{id: int, period_end: string, status: string, assets: int, total: float, journal: array{id: int, reference_no: string|null}|null, error: string|null, run_by: string|null, created_at: string}>, next_period: string, estimate: float, can_run: bool}
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $next = $this->depreciation->nextPeriod();
        $estimate = Asset::with('category')->whereIn('status', Asset::ON_BOOKS)->whereNotNull('dep_from')->get()->sum(fn ($a) => $this->depreciation->due($a, $next->toDateString()));

        return response()->json([
            'data' => DepreciationRun::with(['journal', 'runner'])->orderByDesc('period_end')->orderByDesc('id')->limit(120)->get()->map(fn ($r) => ['id' => $r->id, 'period_end' => $r->period_end->toDateString(),
                'status' => $r->status, 'assets' => (int) $r->assets, 'total' => $r->total, 'journal' => $r->journal ? ['id' => $r->journal->id, 'reference_no' => $r->journal->reference_no] : null,
                'error' => $r->error, 'run_by' => $r->runner?->display_name ?? 'Automatic', 'created_at' => $r->created_at->toIso8601String()])->values(),
            'next_period' => $next->toDateString(), 'estimate' => round($estimate, 2), 'can_run' => $this->access->manages($request->user()) && $next->lte(today()->endOfMonth()),
        ]);
    }

    /** @response array{data: array{id: int, period_end: string, assets: int, total: float}} */
    public function run(DepreciationRunRequest $request): JsonResponse
    {
        $r = $this->depreciation->run($request->validated()['period_end'], $request->user());

        return response()->json(['data' => ['id' => $r->id, 'period_end' => $r->period_end->toDateString(), 'assets' => (int) $r->assets, 'total' => $r->total]]);
    }

    /** @response array{data: array{number_pattern: string, depreciation_start: string, opening_account_id: int|null, donation_account_id: int|null, gain_loss_account_id: int|null, revaluation_account_id: int|null, impairment_account_id: int|null, confirmation_month: int|null, asset_measurement: string}} */
    public function settings(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $s = AssetSetting::current();

        return response()->json(['data' => ['number_pattern' => $s->number_pattern, 'depreciation_start' => $s->depreciation_start, 'opening_account_id' => $s->opening_account_id,
            'donation_account_id' => $s->donation_account_id, 'gain_loss_account_id' => $s->gain_loss_account_id, 'revaluation_account_id' => $s->revaluation_account_id,
            'impairment_account_id' => $s->impairment_account_id, 'confirmation_month' => $s->confirmation_month,
            'asset_measurement' => app(\Modules\Accounting\Services\AccountingPolicies::class)->policy('asset_measurement')]]);
    }

    /** @response array{data: array{number_pattern: string, depreciation_start: string, opening_account_id: int|null, donation_account_id: int|null, gain_loss_account_id: int|null, revaluation_account_id: int|null, impairment_account_id: int|null, confirmation_month: int|null, asset_measurement: string}} */
    public function updateSettings(AssetSettingsRequest $request): JsonResponse
    {
        AssetSetting::current()->fill($request->validated())->save();

        return $this->settings($request);
    }
}
