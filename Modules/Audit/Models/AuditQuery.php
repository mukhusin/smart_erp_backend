<?php

namespace Modules\Audit\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditQuery extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['open' => 'Waiting for an answer', 'answered' => 'Answered', 'closed' => 'Closed'];

    public const AREAS = ['ledger' => 'Ledger', 'payments' => 'Payments', 'receivables' => 'Receipts and invoices', 'procurement' => 'Procurement', 'stores' => 'Stores', 'assets' => 'Assets',
        'payroll' => 'Payroll', 'hr' => 'HR', 'access' => 'System access', 'other' => 'Other'];

    protected $fillable = ['company_id', 'number', 'title', 'question', 'reference', 'link', 'area', 'assigned_to', 'raised_by', 'due_date', 'status', 'response', 'responded_by', 'responded_at',
        'closing_note', 'closed_at'];

    protected $casts = ['due_date' => 'date', 'responded_at' => 'datetime', 'closed_at' => 'datetime'];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function responder()
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
