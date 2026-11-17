<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProcurementSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'clearing_account_id', 'direct_limit', 'min_quotes', 'match_tolerance_percent', 'retention_account_id', 'damages_account_id', 'advance_account_id', 'variation_limit_percent', 'expiry_warning_days', 'award_acceptance_days'];

    protected $casts = ['direct_limit' => 'float', 'min_quotes' => 'integer', 'match_tolerance_percent' => 'float', 'variation_limit_percent' => 'float'];

    public static function current(): self
    {
        return static::firstOrNew([], ['direct_limit' => 1000000, 'min_quotes' => 3, 'match_tolerance_percent' => 0, 'variation_limit_percent' => 15, 'expiry_warning_days' => 30, 'award_acceptance_days' => 7]);
    }
}
