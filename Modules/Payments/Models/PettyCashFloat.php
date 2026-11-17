<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\CashBankAccount;

class PettyCashFloat extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'cash_bank_account_id', 'custodian_id', 'float_amount', 'max_voucher', 'replenish_from_id', 'is_active'];

    protected $casts = ['float_amount' => 'float', 'max_voucher' => 'float', 'is_active' => 'boolean'];

    public function cash()
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function replenishFrom()
    {
        return $this->belongsTo(CashBankAccount::class, 'replenish_from_id');
    }

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function vouchers()
    {
        return $this->hasMany(PettyCashVoucher::class);
    }

    public function replenishments()
    {
        return $this->hasMany(PettyCashReplenishment::class);
    }
}
