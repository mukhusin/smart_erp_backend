<?php

namespace Modules\Assets\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class AssetChange extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['improvement' => 'Improvement (adds to cost)', 'life' => 'Useful life review', 'revaluation' => 'Revaluation', 'impairment' => 'Impairment'];

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'asset_id', 'kind', 'date', 'amount', 'months', 'credit_account_id', 'valuer', 'reason', 'book_value', 'status', 'journal_entry_id',
        'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'amount' => 'float', 'book_value' => 'float', 'decided_at' => 'datetime'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function creditAccount()
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
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
