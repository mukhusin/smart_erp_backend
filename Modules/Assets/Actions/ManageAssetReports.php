<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetReport;
use Modules\Core\Notifications\Notice;

/** Employee self service: report a fault, hand an asset back, confirm the assets held this year. */
class ManageAssetReports
{
    public function __construct(private readonly ManageAssets $assets) {}

    /** Assets the user holds, and those of the departments they head. */
    public function heldBy(User $user)
    {
        $employee = $user->employee;
        if (! $employee) {
            return collect();
        }
        $heads = \App\Models\HeadOfDepartment::where('employee_id', $employee->id)->where('is_active', true)->pluck('department_id');

        return Asset::with(['category', 'department'])->whereIn('status', Asset::ON_BOOKS)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->orWhere(fn ($q) => $q->whereNull('employee_id')->whereIn('department_id', $heads)))->orderBy('number')->get();
    }

    private function mine(Asset $asset, User $user): void
    {
        abort_unless($this->heldBy($user)->contains('id', $asset->id), 403, 'This asset is not held by you.');
    }

    public function report(Asset $asset, array $data, ?UploadedFile $photo, User $user): AssetReport
    {
        $this->mine($asset, $user);
        if ($data['type'] === 'return' && AssetReport::where('asset_id', $asset->id)->where('type', 'return')->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['type' => 'A return of this asset is already waiting for the asset officer.']);
        }
        $r = AssetReport::create(['asset_id' => $asset->id, 'type' => $data['type'], 'reported_by' => $user->id, 'description' => $data['description'] ?? null,
            'condition' => $data['condition'] ?? null, 'photo_path' => $photo?->store('asset-reports', 'local'), 'status' => 'open']);
        foreach (app(\Modules\Assets\Support\AssetAccess::class)->officers() as $officer) {
            $officer->notify(new Notice($data['type'] === 'fault' ? 'Asset fault reported' : 'Asset being returned', "{$asset->number} {$asset->name}: ".($data['description'] ?? 'returned'), '/assets/reports', 'pc-display'));
        }

        return $r;
    }

    public function handle(AssetReport $report, array $data, User $by): AssetReport
    {
        if (! in_array($report->status, ['open', 'repair'], true)) {
            throw ValidationException::withMessages(['report' => 'This report is already closed.']);
        }
        $allowed = $report->type === 'return' ? ['confirmed', 'declined'] : ['repair', 'resolved', 'suspended'];
        if (! in_array($data['status'], $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Choose one of: '.implode(', ', $allowed).'.']);
        }

        return DB::transaction(function () use ($report, $data, $by) {
            $asset = $report->asset;
            if ($data['status'] === 'confirmed') {
                $this->assets->returnAsset($asset, ['condition' => $report->condition ?? $asset->condition, 'location' => $data['location'] ?? null], $by);
            } elseif ($data['status'] === 'suspended') {
                $this->assets->setStatus($asset, 'suspended');
            } elseif ($data['status'] === 'resolved' && $asset->status === 'suspended') {
                $this->assets->setStatus($asset, 'active');
            }
            $report->update(['status' => $data['status'], 'response' => $data['response'] ?? null, 'handled_by' => $by->id, 'handled_at' => now()]);
            $report->reporter?->notify(new Notice('Asset report answered', "{$asset->number}: ".AssetReport::STATUS_LABELS[$data['status']].($data['response'] ? " — {$data['response']}" : ''), '/self-service/assets', 'pc-display'));

            return $report->refresh();
        });
    }

    /** @return int assets confirmed */
    public function confirm(User $user): int
    {
        $year = (int) today()->year;
        $ids = $this->heldBy($user)->pluck('id');
        Asset::whereIn('id', $ids)->update(['confirmed_year' => $year]);

        return $ids->count();
    }
}
