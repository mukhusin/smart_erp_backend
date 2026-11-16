<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class PaymentList extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Paid', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'cash_bank_account_id', 'method', 'date', 'total', 'status', 'journal_entry_id', 'prepared_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'total' => 'float', 'decided_at' => 'datetime'];

    public function bank()
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function items()
    {
        return $this->hasMany(PaymentListItem::class)->orderBy('id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
