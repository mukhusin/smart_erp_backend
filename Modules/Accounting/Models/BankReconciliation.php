<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class BankReconciliation extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'rejected' => 'Returned', 'completed' => 'Completed'];

    protected $fillable = ['company_id', 'number', 'cash_bank_account_id', 'statement_date', 'statement_balance', 'book_balance', 'difference', 'notes', 'status',
        'prepared_by', 'submitted_at', 'decision_comment', 'completed_at'];

    protected $casts = ['statement_date' => 'date', 'statement_balance' => 'float', 'book_balance' => 'float', 'difference' => 'float',
        'submitted_at' => 'datetime', 'completed_at' => 'datetime'];

    public function bank()
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function statementLines()
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('date')->orderBy('id');
    }

    public function clearedLines()
    {
        return $this->hasMany(JournalEntryLine::class);
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
