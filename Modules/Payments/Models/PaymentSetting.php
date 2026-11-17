<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;

class PaymentSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'payable_account_id', 'withholding_account_id', 'imprest_account_id', 'imprest_retire_days', 'imprest_one_open', 'require_attachment'];

    protected $casts = ['require_attachment' => 'boolean', 'imprest_one_open' => 'boolean', 'imprest_retire_days' => 'integer'];

    public static function current(): self
    {
        return static::firstOrNew([], ['require_attachment' => true, 'imprest_retire_days' => 14, 'imprest_one_open' => true]);
    }

    public function payableAccount()
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function withholdingAccount()
    {
        return $this->belongsTo(Account::class, 'withholding_account_id');
    }
}
