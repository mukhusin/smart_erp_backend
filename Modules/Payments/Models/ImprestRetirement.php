<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class ImprestRetirement extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'number', 'payment_request_id', 'date', 'notes', 'advanced', 'spent', 'returned', 'cash_bank_account_id', 'return_reference', 'status',
        'journal_entry_id', 'prepared_by', 'submitted_at', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'advanced' => 'float', 'spent' => 'float', 'returned' => 'float', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function lines()
    {
        return $this->hasMany(ImprestRetirementLine::class)->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(PaymentAttachment::class)->orderBy('id');
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

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
