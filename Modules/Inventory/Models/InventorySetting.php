<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventorySetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'opening_account_id', 'donation_account_id', 'expiry_warning_days', 'slow_moving_days'];

    protected $casts = ['expiry_warning_days' => 'integer', 'slow_moving_days' => 'integer'];

    public static function current(): self
    {
        return static::firstOrNew([], ['expiry_warning_days' => 90, 'slow_moving_days' => 180]);
    }
}
