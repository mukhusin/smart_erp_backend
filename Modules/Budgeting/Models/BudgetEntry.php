<?php

namespace Modules\Budgeting\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BudgetEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const BALANCES = ['approved' => 'Approved', 'released' => 'Released', 'reserved' => 'Held by requests', 'committed' => 'Committed', 'spent' => 'Spent'];

    protected $fillable = ['company_id', 'budget_item_id', 'balance', 'amount', 'source_type', 'source_id', 'note', 'user_id'];

    protected $casts = ['amount' => 'float'];

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
