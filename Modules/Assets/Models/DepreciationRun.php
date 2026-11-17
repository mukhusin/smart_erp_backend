<?php

namespace Modules\Assets\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;

class DepreciationRun extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'period_end', 'status', 'assets', 'total', 'journal_entry_id', 'error', 'run_by'];

    protected $casts = ['period_end' => 'date', 'total' => 'float'];

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function runner()
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
