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
        'voided' => 'Voided', 'paid' => 'Paid', 'void_pending' => 'Void waiting for approval',
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
        return $this->listItems()->whereHas('list', fn ($q) => $q->where('status', 'pending'))->exists();
    }

    public function hasPaidItems(): bool
    {
        return $this->listItems()->where('status', 'paid')->whereHas('list', fn ($q) => $q->where('status', 'approved'))->exists();
    }

    /** Payees with money still to pay: not on a waiting list and not paid. */
    public function outstandingPayees()
    {
        return $this->request->payees()->whereRaw('amount - withholding > 0')
            ->whereDoesntHave('listItems', fn ($i) => PaymentRequestPayee::settling($i))->get();
    }

    public function settledStatus(): string
    {
        return $this->outstandingPayees()->isEmpty() ? 'paid' : 'approved';
    }
}
