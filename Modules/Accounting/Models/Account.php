<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expense'];

    public const DEBIT_NORMAL = ['asset', 'expense'];

    public const CASH_FLOW = ['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'];

    protected $fillable = ['company_id', 'parent_id', 'code', 'gfs_code', 'name', 'type', 'is_postable', 'is_budgetable', 'cash_flow', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'is_postable' => 'boolean', 'is_budgetable' => 'boolean'];

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }

    /** Cash flow section of money moving through this account; equity defaults to financing, everything else to operating. */
    public function cashFlow(): string
    {
        return $this->cash_flow ?: ($this->type === 'equity' ? 'financing' : 'operating');
    }

    public function balance(float $debit, float $credit): float
    {
        return round(in_array($this->type, self::DEBIT_NORMAL, true) ? $debit - $credit : $credit - $debit, 2);
    }
}
