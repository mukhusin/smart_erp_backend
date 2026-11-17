<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetTransfer;
use Modules\Assets\Services\Derecognition;
use Modules\Assets\Workflow\AssetTransferProcess;
use Modules\Core\Support\Numbering;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/** Moves between departments, branches or people (permanent or temporary), and transfers out to another organisation. */
class ManageAssetTransfers
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly ManageAssets $assets, private readonly Derecognition $derecognition) {}

    public function create(Asset $asset, array $data, User $by): AssetTransfer
    {
        if (! in_array($asset->status, ['registered', 'active', 'stored', 'suspended'], true)) {
            throw ValidationException::withMessages(['asset' => 'Only an asset on the register can be transferred.']);
        }
        if (AssetTransfer::where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['asset' => 'A transfer of this asset is already waiting.']);
        }
        $external = ($data['kind'] ?? 'internal') === 'external';
        if ($external && empty($data['to_organisation'])) {
            throw ValidationException::withMessages(['to_organisation' => 'Name the organisation receiving the asset.']);
        }
        if (! $external && empty($data['to_department_id']) && empty($data['to_employee_id']) && empty($data['to_branch_id']) && empty($data['to_location'])) {
            throw ValidationException::withMessages(['to_department_id' => 'Choose where the asset goes.']);
        }
        $permanent = $external || ($data['permanent'] ?? true);
        if (! $permanent && empty($data['return_date'])) {
            throw ValidationException::withMessages(['return_date' => 'A temporary transfer needs a return date.']);
        }

        return DB::transaction(function () use ($asset, $data, $by, $external, $permanent) {
            $t = AssetTransfer::create(['number' => Numbering::next(AssetTransfer::class, 'ATR', $data['date']), 'asset_id' => $asset->id, 'kind' => $external ? 'external' : 'internal',
                'permanent' => $permanent, 'date' => $data['date'], 'return_date' => $permanent ? null : $data['return_date'], 'to_branch_id' => $data['to_branch_id'] ?? null,
                'to_department_id' => $data['to_department_id'] ?? null, 'to_employee_id' => $data['to_employee_id'] ?? null, 'to_location' => $data['to_location'] ?? null,
                'to_organisation' => $external ? $data['to_organisation'] : null, 'reason' => $data['reason'], 'status' => 'pending', 'created_by' => $by->id,
                'previous' => ['company_branch_id' => $asset->company_branch_id, 'department_id' => $asset->department_id, 'employee_id' => $asset->employee_id, 'location' => $asset->location]]);
            if (! $this->workflow->start(AssetTransferProcess::KEY, $t, $by)) {
                $this->approved($t);
            }

            return $t->refresh();
        });
    }

    public function approved(AssetTransfer $t): void
    {
        DB::transaction(function () use ($t) {
            $asset = $t->asset;
            $by = $t->creator;
            if ($t->kind === 'external') {
                $result = $this->derecognition->remove($asset, $t->date->toDateString(), 0, null, "Transferred to {$t->to_organisation}", 'transfer_out', $t, $by);
                $t->update(['status' => 'approved', 'journal_entry_id' => $result['journal']?->id, 'decided_at' => now()]);

                return;
            }
            $this->assets->assign($asset, ['department_id' => $t->to_department_id ?? ($t->to_employee_id ? null : $asset->department_id), 'employee_id' => $t->to_employee_id,
                'location' => $t->to_location ?? $asset->location, 'date' => $t->date->toDateString(), 'reason' => "Transfer {$t->number}: {$t->reason}"], $by);
            if ($t->to_branch_id) {
                $asset->refresh()->update(['company_branch_id' => $t->to_branch_id]);
            }
            $t->update(['status' => 'approved', 'decided_at' => now()]);
        });
    }

    /** A temporary transfer comes back to the holder it had before. */
    public function markReturned(AssetTransfer $t, User $by): AssetTransfer
    {
        if ($t->status !== 'approved' || $t->permanent || $t->kind !== 'internal') {
            throw ValidationException::withMessages(['transfer' => 'Only an approved temporary transfer can be returned.']);
        }

        return DB::transaction(function () use ($t, $by) {
            $p = $t->previous ?? [];
            if (! empty($p['department_id']) || ! empty($p['employee_id'])) {
                $this->assets->assign($t->asset, ['department_id' => $p['department_id'] ?? null, 'employee_id' => $p['employee_id'] ?? null, 'location' => $p['location'] ?? null,
                    'reason' => "Back from temporary transfer {$t->number}"], $by);
            } else {
                $this->assets->returnAsset($t->asset, ['condition' => $t->asset->condition], $by);
            }
            $t->asset->refresh()->update(['company_branch_id' => $p['company_branch_id'] ?? $t->asset->company_branch_id]);
            $t->update(['status' => 'returned', 'returned_at' => now()]);

            return $t->refresh();
        });
    }

    public function rejected(AssetTransfer $t, ?string $comment, bool $withdrawn): void
    {
        $t->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
    }

    public function withdraw(AssetTransfer $t, User $by): AssetTransfer
    {
        if ($t->status !== 'pending' || $t->created_by !== $by->id) {
            throw ValidationException::withMessages(['transfer' => 'Only the person who asked can withdraw it while it waits.']);
        }
        $instance = $t->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($t, null, true);

        return $t->refresh();
    }
}
