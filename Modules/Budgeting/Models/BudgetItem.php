<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FundSource;
use Modules\Planning\Models\SubActivity;
use Modules\Tasks\Models\TaskActivity;

class BudgetItem extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'budget_id', 'department_id', 'task_activity_id', 'sub_activity_id', 'account_id', 'fund_source_id', 'description',
        'quantity', 'unit', 'unit_cost', 'frequency', 'original_amount', 'revised_amount',
    ];

    protected $casts = ['quantity' => 'float', 'unit_cost' => 'float', 'frequency' => 'float', 'original_amount' => 'float', 'revised_amount' => 'float'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function activity()
    {
        return $this->belongsTo(TaskActivity::class, 'task_activity_id');
    }

    public function subActivity()
    {
        return $this->belongsTo(SubActivity::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function fundSource()
    {
        return $this->belongsTo(FundSource::class);
    }

    public function periods()
    {
        return $this->hasMany(BudgetItemPeriod::class)->orderBy('period');
    }

    public function entries()
    {
        return $this->hasMany(BudgetEntry::class);
    }

    public function label(): string
    {
        $what = $this->subActivity?->label() ?? $this->activity?->title ?? $this->description;

        return trim(($this->account ? $this->account->code.' ' : '').($what ?: $this->account?->name ?: 'Budget item'));
    }
}
