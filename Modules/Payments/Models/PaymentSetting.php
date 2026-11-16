<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\Account;

class PaymentSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'payable_account_id', 'withholding_account_id', 'require_attachment'];

    protected $casts = ['require_attachment' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['require_attachment' => true]);
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
