<?php

namespace Modules\Receivables\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class Receipt extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['invoice' => 'Against invoices', 'direct' => 'Without invoice'];

    public const METHODS = ['cash' => 'Cash', 'bank' => 'Bank deposit / transfer', 'mobile' => 'Mobile money', 'cheque' => 'Cheque', 'card' => 'Card'];

    public const STATUS_LABELS = ['posted' => 'Received', 'void_pending' => 'Void waiting for approval', 'voided' => 'Voided'];

    protected $fillable = ['company_id', 'number', 'kind', 'customer_id', 'payer_name', 'date', 'cash_bank_account_id', 'method', 'reference', 'amount', 'notes', 'status',
        'journal_entry_id', 'created_by', 'void_reason', 'voided_at'];

    protected $casts = ['date' => 'date', 'amount' => 'float', 'voided_at' => 'datetime'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function bank()
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function allocations()
    {
        return $this->hasMany(ReceiptAllocation::class)->orderBy('id');
    }

    public function lines()
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('id');
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
