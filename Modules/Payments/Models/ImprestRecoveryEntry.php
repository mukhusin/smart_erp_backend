<?php

namespace Modules\Payments\Models;

use App\Models\PayrollSheet;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;

class ImprestRecoveryEntry extends Model
{
    protected $fillable = ['imprest_recovery_id', 'payroll_sheet_id', 'amount', 'journal_entry_id'];

    protected $casts = ['amount' => 'float'];

    public function sheet()
    {
        return $this->belongsTo(PayrollSheet::class, 'payroll_sheet_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
