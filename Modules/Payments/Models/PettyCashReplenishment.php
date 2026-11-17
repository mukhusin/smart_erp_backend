<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class PettyCashReplenishment extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Topped up', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'petty_cash_float_id', 'from_account_id', 'date', 'amount', 'status', 'journal_entry_id', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'amount' => 'float', 'decided_at' => 'datetime'];

    public function float()
    {
        return $this->belongsTo(PettyCashFloat::class, 'petty_cash_float_id');
    }

    public function from()
    {
        return $this->belongsTo(CashBankAccount::class, 'from_account_id');
    }

    public function vouchers()
    {
        return $this->hasMany(PettyCashVoucher::class);
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
