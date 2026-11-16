<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BudgetCommitment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'budget_item_id', 'source_type', 'source_id', 'amount', 'status'];

    protected $casts = ['amount' => 'float'];

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }

    public function source()
    {
        return $this->morphTo();
    }
}
