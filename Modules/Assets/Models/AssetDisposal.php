<?php

namespace Modules\Assets\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class AssetDisposal extends Model
{
    use Auditable, BelongsToCompany;

    public const METHODS = ['sale' => 'Sale', 'auction' => 'Auction', 'donation' => 'Donated away', 'write_off' => 'Written off (lost, stolen, destroyed)', 'retirement' => 'Retired from use'];

    public const STATUS_LABELS = ['pending' => 'Waiting for the board', 'approved' => 'Disposed', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'asset_id', 'method', 'date', 'proceeds', 'cash_bank_account_id', 'buyer', 'reason', 'minutes_path', 'minutes_name', 'book_value',
        'gain_loss', 'status', 'journal_entry_id', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'proceeds' => 'float', 'book_value' => 'float', 'gain_loss' => 'float', 'decided_at' => 'datetime'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function bank()
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
