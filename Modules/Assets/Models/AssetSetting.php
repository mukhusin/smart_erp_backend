<?php

namespace Modules\Assets\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssetSetting extends Model
{
    use Auditable, BelongsToCompany;

    public const START = ['in_use' => 'From the date put in use', 'acquisition' => 'From the acquisition date'];

    protected $fillable = ['company_id', 'number_pattern', 'depreciation_start', 'opening_account_id', 'donation_account_id', 'gain_loss_account_id', 'revaluation_account_id',
        'impairment_account_id', 'confirmation_month'];

    public static function current(): self
    {
        return static::firstOrNew([], ['number_pattern' => '{CAT}-{YEAR}-{SEQ}', 'depreciation_start' => 'in_use']);
    }
}
