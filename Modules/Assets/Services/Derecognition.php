<?php

namespace Modules\Assets\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\PostingService;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetSetting;

/** Takes an asset off the books: depreciation up to the date, then cost and accumulated depreciation removed with any proceeds and the gain or loss. */
class Derecognition
{
    public function __construct(private readonly PostingService $posting, private readonly Depreciation $depreciation) {}

    /** @return array{journal: ?JournalEntry, book_value: float, gain_loss: float} */
    public function remove(Asset $asset, string $date, float $proceeds, ?int $bankId, string $label, string $kind, $source, User $by): array
    {
        $asset->loadMissing('category');
        $c = $asset->category;
        $gainLoss = AssetSetting::current()->gain_loss_account_id;
        if (! $gainLoss) {
            throw ValidationException::withMessages(['asset' => 'Set the gain or loss on disposal account in Fixed Assets → Settings first.']);
        }
        $bank = $proceeds > 0 ? CashBankAccount::find($bankId) : null;
        if ($proceeds > 0 && ! $bank?->account_id) {
            throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose the bank or cash account that received the money.']);
        }
        $catchUp = $this->depreciation->due($asset, $date);
        if ($catchUp > 0) {
            $this->depreciation->charge($asset, $catchUp, $date, $source, 'Depreciation to the disposal date');
        }
        $value = $asset->refresh()->valueAt();
        $result = round($proceeds - $value['book_value'], 2);
        $lines = [];
        if ($catchUp > 0) {
            $lines[] = ['account_id' => $c->depreciation_account_id, 'debit' => $catchUp, 'description' => "Depreciation {$asset->number} to {$date}", 'department_id' => $asset->department_id];
            $lines[] = ['account_id' => $c->accumulated_account_id, 'credit' => $catchUp, 'description' => "Depreciation {$asset->number} to {$date}"];
        }
        if ($value['depreciation'] > 0) {
            $lines[] = ['account_id' => $c->accumulated_account_id, 'debit' => $value['depreciation'], 'description' => "Accumulated depreciation {$asset->number}"];
        }
        if ($proceeds > 0) {
            $lines[] = ['account_id' => $bank->account_id, 'debit' => round($proceeds, 2), 'description' => "Proceeds {$asset->number}"];
        }
        $lines[] = ['account_id' => $c->asset_account_id, 'credit' => $value['cost'], 'description' => "Cost {$asset->number}"];
        if ($result != 0) {
            $lines[] = ['account_id' => $gainLoss, $result > 0 ? 'credit' : 'debit' => abs($result), 'description' => ($result > 0 ? 'Gain' : 'Loss')." on {$asset->number}", 'department_id' => $asset->department_id];
        }
        $journal = $this->posting->post($date, "{$asset->number} {$label}", $lines, $by, 'asset', $source);
        \Modules\Assets\Models\AssetEntry::where('asset_id', $asset->id)->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->update(['journal_entry_id' => $journal->id]);
        $asset->entries()->create(['date' => $date, 'kind' => $kind, 'cost' => -$value['cost'], 'depreciation' => -$value['depreciation'], 'description' => $label,
            'journal_entry_id' => $journal->id, 'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey()]);
        $asset->assignments()->whereNull('to_date')->update(['to_date' => $date, 'returned_from' => 'disposed']);
        $asset->update(['status' => 'disposed', 'employee_id' => null]);

        return ['journal' => $journal, 'book_value' => $value['book_value'], 'gain_loss' => $result];
    }
}
