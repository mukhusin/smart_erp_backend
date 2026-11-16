<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AccountingSetting extends Model
{
    use Auditable, BelongsToCompany;

    public const PHASING = ['quarter' => 'By quarter', 'month' => 'By month', 'year' => 'Year total'];

    public const CONTROL = ['off' => 'Off (report only)', 'warn' => 'Warn and ask for approval', 'block' => 'Block'];

    public const RELEASE = ['period' => 'Finance opens each quarter or month', 'none' => 'The whole approved budget can be spent at once'];

    protected $fillable = ['company_id', 'use_gfs', 'budget_phasing', 'budget_control', 'budget_release'];

    protected $casts = ['use_gfs' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['use_gfs' => false, 'budget_phasing' => 'quarter', 'budget_control' => 'warn', 'budget_release' => 'period']);
    }
}
