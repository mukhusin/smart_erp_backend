<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'cash_bank_account_id', 'bank_reconciliation_id', 'date', 'description', 'reference', 'amount', 'journal_entry_line_id', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'float'];

    public function bookLine()
    {
        return $this->belongsTo(JournalEntryLine::class, 'journal_entry_line_id');
    }
}
