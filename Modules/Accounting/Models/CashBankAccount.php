<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashBankAccount extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'kind', 'account_id', 'bank_name', 'account_number', 'currency', 'is_active', 'feed_config', 'feed_enabled', 'feed_checked_at', 'feed_error'];

    protected $casts = ['is_active' => 'boolean', 'feed_config' => 'encrypted:array', 'feed_enabled' => 'boolean', 'feed_checked_at' => 'datetime'];

    protected $hidden = ['feed_config'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
