<?php

namespace Modules\Assets\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Assets\Actions\ManageAssetChanges;
use Modules\Assets\Actions\ManageAssetDisposals;
use Modules\Assets\Actions\ManageAssetReports;
use Modules\Assets\Actions\ManageAssetTransfers;
use Modules\Assets\Http\Requests\AssetChangeRequest;
use Modules\Assets\Http\Requests\AssetDisposalRequest;
use Modules\Assets\Http\Requests\AssetTransferRequest;
use Modules\Assets\Http\Requests\HandleAssetReportRequest;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetChange;
use Modules\Assets\Models\AssetDisposal;
use Modules\Assets\Models\AssetReport;
use Modules\Assets\Models\AssetTransfer;
use Modules\Assets\Support\AssetAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Assets: changes, transfers, disposals and reports
 */
class AssetActivityController extends Controller
{
    public function __construct(private readonly AssetAccess $access) {}

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function change(AssetChangeRequest $request, Asset $asset, ManageAssetChanges $actions): JsonResponse
    {
        $c = $actions->create($asset, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawChange(Request $request, AssetChange $change, ManageAssetChanges $actions): JsonResponse
    {
        $c = $actions->withdraw($change, $request->user());

        return response()->json(['data' => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function transfer(AssetTransferRequest $request, Asset $asset, ManageAssetTransfers $actions): JsonResponse
    {
        $t = $actions->create($asset, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawTransfer(Request $request, AssetTransfer $transfer, ManageAssetTransfers $actions): JsonResponse
    {
        $t = $actions->withdraw($transfer, $request->user());

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function returnTransfer(Request $request, AssetTransfer $transfer, ManageAssetTransfers $actions): JsonResponse
    {
        $t = $actions->markReturned($transfer, $request->user());

        return response()->json(['data' => ['id' => $t->id, 'number' => $t->number, 'status' => $t->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function dispose(AssetDisposalRequest $request, Asset $asset, ManageAssetDisposals $actions): JsonResponse
    {
        $d = $actions->create($asset, collect($request->validated())->except('minutes')->all(), $request->file('minutes'), $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawDisposal(Request $request, AssetDisposal $disposal, ManageAssetDisposals $actions): JsonResponse
    {
        $d = $actions->withdraw($disposal, $request->user());

        return response()->json(['data' => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status]]);
    }

    public function minutes(Request $request, AssetDisposal $disposal): StreamedResponse
    {
        abort_unless($this->access->sees($request->user()) && $disposal->minutes_path, 404);

        return Storage::disk('local')->download($disposal->minutes_path, $disposal->minutes_name);
    }

    /**
     * Fault and return reports from staff.
     *
     * @response array{data: list<array{id: int, asset_id: int, asset: string, type: string, type_label: string, description: string|null, condition: string|null, has_photo: bool, status: string, status_label: string, response: string|null, reported_by: string|null, created_at: string}>}
     */
    #[QueryParameter('status', description: '`open` (default: open and in repair) or `all`.', type: 'string')]
    public function reports(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $rows = AssetReport::with(['asset', 'reporter'])->when($request->query('status', 'open') === 'open', fn ($q) => $q->whereIn('status', ['open', 'repair']))->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'asset_id' => $r->asset_id, 'asset' => "{$r->asset->number} {$r->asset->name}", 'type' => $r->type,
            'type_label' => AssetReport::TYPES[$r->type], 'description' => $r->description, 'condition' => $r->condition, 'has_photo' => (bool) $r->photo_path, 'status' => $r->status,
            'status_label' => AssetReport::STATUS_LABELS[$r->status] ?? $r->status, 'response' => $r->response, 'reported_by' => $r->reporter?->display_name, 'created_at' => $r->created_at->toIso8601String()])->values()]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function handleReport(HandleAssetReportRequest $request, AssetReport $report, ManageAssetReports $actions): JsonResponse
    {
        $r = $actions->handle($report, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]]);
    }

    public function photo(Request $request, AssetReport $report): StreamedResponse
    {
        abort_unless(($this->access->sees($request->user()) || $report->reported_by === $request->user()->id) && $report->photo_path, 404);

        return Storage::disk('local')->download($report->photo_path);
    }
}
