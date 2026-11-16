<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class PaymentVoucher extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = [
        'pending' => 'Waiting for approval', 'approved' => 'Approved, not paid', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn',
        'voided' => 'Voided', 'paid' => 'Paid',
    ];

    protected $fillable = ['company_id', 'number', 'payment_request_id', 'cash_bank_account_id', 'date', 'description', 'gross', 'withholding', 'net', 'status',
        'journal_entry_id', 'prepared_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'gross' => 'float', 'withholding' => 'float', 'net' => 'float', 'decided_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function bank()
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function listItems()
    {
        return $this->hasMany(PaymentListItem::class);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function inOpenList(): bool
    {
        return $this->listItems()->whereHas('list', fn ($q) => $q->whereIn('status', ['pending', 'approved']))->exists();
    }
}
