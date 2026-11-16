<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;

class ItemCategory extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['store' => 'Store item', 'asset' => 'Fixed asset', 'intangible' => 'Intangible asset', 'wip' => 'Work in progress', 'service' => 'Service'];

    public const ACCOUNTS = ['inventory' => 'Inventory (stock) account', 'expense' => 'Expense when issued or bought', 'revenue' => 'Sales revenue',
        'asset' => 'Asset cost account', 'depreciation' => 'Depreciation expense', 'accumulated' => 'Accumulated depreciation'];

    protected $fillable = ['company_id', 'code', 'name', 'type', 'inventory_account_id', 'expense_account_id', 'revenue_account_id', 'asset_account_id', 'depreciation_account_id',
        'accumulated_account_id', 'useful_life_months', 'residual_percent', 'capitalisation_threshold', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'useful_life_months' => 'integer', 'residual_percent' => 'float', 'capitalisation_threshold' => 'float'];

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function account(string $key): ?Account
    {
        return $this->{"{$key}_account_id"} ? Account::find($this->{"{$key}_account_id"}) : null;
    }
}
