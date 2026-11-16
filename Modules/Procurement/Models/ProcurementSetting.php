<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProcurementSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'clearing_account_id', 'direct_limit', 'min_quotes', 'match_tolerance_percent'];

    protected $casts = ['direct_limit' => 'float', 'min_quotes' => 'integer', 'match_tolerance_percent' => 'float'];

    public static function current(): self
    {
        return static::firstOrNew([], ['direct_limit' => 1000000, 'min_quotes' => 3, 'match_tolerance_percent' => 0]);
    }
}
