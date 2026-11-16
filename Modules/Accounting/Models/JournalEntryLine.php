<?php

namespace Modules\Accounting\Models;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Models\TaskActivity;

class JournalEntryLine extends Model
{
    protected $fillable = ['journal_entry_id', 'line_no', 'account_id', 'department_id', 'fund_source_id', 'task_activity_id', 'project_id', 'budget_item_id', 'description', 'debit', 'credit'];

    protected $casts = ['debit' => 'float', 'credit' => 'float', 'line_no' => 'integer'];

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function fundSource()
    {
        return $this->belongsTo(FundSource::class);
    }

    public function activity()
    {
        return $this->belongsTo(TaskActivity::class, 'task_activity_id');
    }
}
