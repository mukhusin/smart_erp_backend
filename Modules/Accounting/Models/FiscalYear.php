<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['open' => 'Open', 'closing' => 'Closing (waiting for approval)', 'closed' => 'Closed'];

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'status', 'closing_journal_id', 'close_requested_by', 'closed_at', 'decision_comment'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];

    public function periods()
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('number');
    }

    public function statements()
    {
        return $this->hasMany(FiscalYearStatement::class);
    }

    public function closingJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'closing_journal_id');
    }

    public function workflow()
    {
        return $this->morphOne(\Modules\Workflow\Models\WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public static function containing($date): ?self
    {
        return static::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
    }
}
