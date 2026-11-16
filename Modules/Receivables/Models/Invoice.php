<?php

namespace Modules\Receivables\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class Invoice extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = [
        'draft' => 'Draft', 'pending' => 'Waiting for approval', 'rejected' => 'Returned', 'open' => 'Not paid', 'partial' => 'Part paid', 'paid' => 'Paid', 'void' => 'Void',
    ];

    public const ISSUED = ['open', 'partial', 'paid'];

    protected $fillable = ['company_id', 'number', 'customer_id', 'date', 'due_date', 'reference', 'notes', 'total', 'paid', 'credited', 'status', 'journal_entry_id',
        'created_by', 'decision_comment', 'issued_at'];

    protected $casts = ['date' => 'date', 'due_date' => 'date', 'total' => 'float', 'paid' => 'float', 'credited' => 'float', 'issued_at' => 'datetime'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    public function allocations()
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
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

    public function balance(): float
    {
        return round($this->total - $this->paid - $this->credited, 2);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function refreshBalance(): void
    {
        $paid = (float) $this->allocations()->whereHas('receipt', fn ($q) => $q->whereIn('status', ['posted', 'void_pending']))->sum('amount');
        $credited = (float) $this->creditNotes()->where('status', 'approved')->sum('amount');
        $balance = round($this->total - $paid - $credited, 2);
        $status = in_array($this->status, self::ISSUED, true) ? ($balance <= 0 ? 'paid' : ($paid + $credited > 0 ? 'partial' : 'open')) : $this->status;
        $this->update(['paid' => round($paid, 2), 'credited' => round($credited, 2), 'status' => $status]);
    }
}
