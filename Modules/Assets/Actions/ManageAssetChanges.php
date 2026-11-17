<?php

namespace Modules\Assets\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountingPolicies;
use Modules\Accounting\Services\PostingService;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetChange;
use Modules\Assets\Models\AssetSetting;
use Modules\Assets\Services\Depreciation;
use Modules\Assets\Workflow\AssetChangeProcess;
use Modules\Core\Support\Numbering;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/** Improvements, useful-life reviews, revaluations and impairments: each is approved, posted and starts a new depreciation basis. */
class ManageAssetChanges
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting, private readonly Depreciation $depreciation, private readonly AccountingPolicies $policies) {}

    public function create(Asset $asset, array $data, User $by): AssetChange
    {
        if (! in_array($asset->status, ['registered', 'active', 'stored', 'suspended'], true)) {
            throw ValidationException::withMessages(['asset' => 'Changes are made only to assets on the register.']);
        }
        if (AssetChange::where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['asset' => 'Another change to this asset is waiting for approval.']);
        }
        $asset->loadMissing('category');
        $book = $asset->valueAt()['book_value'];
        $s = AssetSetting::current();
        $kind = $data['kind'];
        $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : null;
        match ($kind) {
            'improvement' => $amount > 0 && ! empty($data['credit_account_id']) && Account::where('is_postable', true)->find($data['credit_account_id'])
                ?: throw ValidationException::withMessages(['amount' => 'Give the amount spent and the account it was paid from or charged to.']),
            'life' => (int) ($data['months'] ?? 0) !== 0 ?: throw ValidationException::withMessages(['months' => 'Give the months to add (or remove, with a minus).']),
            'revaluation' => $this->policies->policy('asset_measurement', $data['date']) === 'revaluation' && $s->revaluation_account_id && $amount !== null && $amount >= 0
                ?: throw ValidationException::withMessages(['kind' => 'Revaluation needs the revaluation model in Accounting → Framework, the revaluation surplus account in settings and the new value.']),
            'impairment' => $s->impairment_account_id && $amount !== null && $amount >= 0 && $amount < $book
                ?: throw ValidationException::withMessages(['amount' => 'Impairment needs the impairment loss account in settings and a tested value below the book value ('.number_format($book, 2).').']),
            default => throw ValidationException::withMessages(['kind' => 'Unknown change.']),
        };

        return DB::transaction(function () use ($asset, $data, $by, $kind, $amount, $book) {
            $change = AssetChange::create(['number' => Numbering::next(AssetChange::class, 'ACH', $data['date']), 'asset_id' => $asset->id, 'kind' => $kind, 'date' => $data['date'],
                'amount' => $amount, 'months' => $kind === 'life' ? (int) $data['months'] : null, 'credit_account_id' => $kind === 'improvement' ? $data['credit_account_id'] : null,
                'valuer' => $data['valuer'] ?? null, 'reason' => $data['reason'], 'book_value' => $book, 'status' => 'pending', 'created_by' => $by->id]);
            if (! $this->workflow->start(AssetChangeProcess::KEY, $change, $by)) {
                $this->approved($change);
            }

            return $change->refresh();
        });
    }

    public function approved(AssetChange $change): void
    {
        DB::transaction(function () use ($change) {
            $asset = $change->asset()->with('category')->first();
            $c = $asset->category;
            $s = AssetSetting::current();
            $date = $change->date->toDateString();
            $book = $asset->valueAt()['book_value'];
            $journal = null;
            $entry = null;
            $label = "{$change->number} {$asset->number} ".AssetChange::KINDS[$change->kind];
            switch ($change->kind) {
                case 'improvement':
                    $journal = $this->posting->post($date, $label, [['account_id' => $c->asset_account_id, 'debit' => $change->amount, 'description' => $change->reason, 'department_id' => $asset->department_id],
                        ['account_id' => $change->credit_account_id, 'credit' => $change->amount, 'description' => $change->reason]], $change->creator, 'asset', $change);
                    $entry = ['kind' => 'improvement', 'cost' => $change->amount];
                    break;
                case 'life':
                    $asset->update(['useful_life_months' => max(1, (int) $asset->useful_life_months + $change->months), 'dep_until' => $asset->dep_until?->copy()->addMonthsNoOverflow($change->months)->toDateString()]);
                    break;
                case 'revaluation':
                    $diff = round($change->amount - $book, 2);
                    if ($diff != 0) {
                        $journal = $this->posting->post($date, $label, [
                            ['account_id' => $c->asset_account_id, $diff > 0 ? 'debit' : 'credit' => abs($diff), 'description' => "Revalued by {$change->valuer}"],
                            ['account_id' => $s->revaluation_account_id, $diff > 0 ? 'credit' : 'debit' => abs($diff), 'description' => "Revaluation {$asset->number}"]], $change->creator, 'asset', $change);
                        $entry = ['kind' => 'revaluation', 'cost' => $diff];
                    }
                    break;
                case 'impairment':
                    $loss = round($book - $change->amount, 2);
                    $journal = $this->posting->post($date, $label, [['account_id' => $s->impairment_account_id, 'debit' => $loss, 'description' => $change->reason, 'department_id' => $asset->department_id],
                        ['account_id' => $c->accumulated_account_id, 'credit' => $loss, 'description' => "Impairment {$asset->number}"]], $change->creator, 'asset', $change);
                    $entry = ['kind' => 'impairment', 'depreciation' => $loss];
                    break;
            }
            if ($entry) {
                $asset->entries()->create($entry + ['date' => $date, 'description' => $change->reason, 'journal_entry_id' => $journal?->id, 'source_type' => $change->getMorphClass(), 'source_id' => $change->id]);
            }
            $change->update(['status' => 'approved', 'journal_entry_id' => $journal?->id, 'decided_at' => now()]);
            $this->depreciation->rebase($asset);
        });
    }

    public function rejected(AssetChange $change, ?string $comment, bool $withdrawn): void
    {
        $change->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
    }

    public function withdraw(AssetChange $change, User $by): AssetChange
    {
        if ($change->status !== 'pending' || $change->created_by !== $by->id) {
            throw ValidationException::withMessages(['change' => 'Only the person who asked can withdraw it while it waits.']);
        }
        $instance = $change->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($change, null, true);

        return $change->refresh();
    }
}
