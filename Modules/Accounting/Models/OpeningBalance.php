<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class OpeningBalance extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = [
        'accounts' => 'Account balances', 'creditors' => 'Creditors (unpaid supplier bills)', 'debtors' => 'Debtors (unpaid customer invoices)', 'breakdowns' => 'Balance breakdowns',
    ];

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'rejected' => 'Returned', 'approved' => 'Approved'];

    protected $fillable = ['company_id', 'number', 'kind', 'date', 'notes', 'status', 'journal_entry_id', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'decided_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(OpeningBalanceLine::class)->orderBy('id');
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

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
