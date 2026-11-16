<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashBankAccount extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'kind', 'account_id', 'bank_name', 'account_number', 'currency', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
