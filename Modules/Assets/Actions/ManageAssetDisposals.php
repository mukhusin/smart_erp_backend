<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetDisposal;
use Modules\Assets\Services\Derecognition;
use Modules\Assets\Workflow\AssetDisposalProcess;
use Modules\Core\Support\Numbering;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageAssetDisposals
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly Derecognition $derecognition) {}

    public function create(Asset $asset, array $data, ?UploadedFile $minutes, User $by): AssetDisposal
    {
        if (! in_array($asset->status, ['registered', 'active', 'stored', 'suspended'], true)) {
            throw ValidationException::withMessages(['asset' => 'Only an asset on the register can be disposed of.']);
        }
        $proceeds = in_array($data['method'], ['sale', 'auction'], true) ? round((float) ($data['proceeds'] ?? 0), 2) : 0.0;

        return DB::transaction(function () use ($asset, $data, $minutes, $by, $proceeds) {
            $d = AssetDisposal::create(['number' => Numbering::next(AssetDisposal::class, 'ADS', $data['date']), 'asset_id' => $asset->id, 'method' => $data['method'], 'date' => $data['date'],
                'proceeds' => $proceeds, 'cash_bank_account_id' => $proceeds > 0 ? ($data['cash_bank_account_id'] ?? null) : null, 'buyer' => $data['buyer'] ?? null, 'reason' => $data['reason'],
                'book_value' => $asset->valueAt()['book_value'], 'status' => 'pending', 'created_by' => $by->id,
                'minutes_path' => $minutes?->store('asset-disposals', 'local'), 'minutes_name' => $minutes?->getClientOriginalName()]);
            $asset->update(['status' => 'disposal_pending']);
            if (! $this->workflow->start(AssetDisposalProcess::KEY, $d, $by)) {
                $this->approved($d);
            }

            return $d->refresh();
        });
    }

    public function approved(AssetDisposal $d): void
    {
        DB::transaction(function () use ($d) {
            $r = $this->derecognition->remove($d->asset, $d->date->toDateString(), (float) $d->proceeds, $d->cash_bank_account_id, AssetDisposal::METHODS[$d->method], 'disposal', $d, $d->creator);
            $d->update(['status' => 'approved', 'journal_entry_id' => $r['journal']?->id, 'book_value' => $r['book_value'], 'gain_loss' => $r['gain_loss'], 'decided_at' => now()]);
        });
    }

    public function rejected(AssetDisposal $d, ?string $comment, bool $withdrawn): void
    {
        DB::transaction(function () use ($d, $comment, $withdrawn) {
            $d->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
            $asset = $d->asset;
            $asset->update(['status' => $asset->employee_id || $asset->department_id ? 'active' : 'stored']);
        });
    }

    public function withdraw(AssetDisposal $d, User $by): AssetDisposal
    {
        if ($d->status !== 'pending' || $d->created_by !== $by->id) {
            throw ValidationException::withMessages(['disposal' => 'Only the person who asked can withdraw it while it waits.']);
        }
        $instance = $d->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($d, null, true);

        return $d->refresh();
    }
}
