<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GratuitySetting extends Model
{
    use Auditable, BelongsToCompany;

    public const BASES = ['basic' => 'Basic salary paid in the month', 'gross' => 'Gross salary of the month'];

    protected $fillable = ['company_id', 'enabled', 'rate_percent', 'base', 'contract_types', 'expense_account_id', 'liability_account_id'];

    protected $casts = ['enabled' => 'boolean', 'rate_percent' => 'float', 'contract_types' => 'array'];

    public static function current(): self
    {
        return static::firstOrNew([], ['enabled' => false, 'rate_percent' => 25, 'base' => 'basic']);
    }

    /** Contract types that earn gratuity; when none are chosen, every type that is not permanent. */
    public function eligibleTypes(): array
    {
        return $this->contract_types ?: NatureOfContract::where('is_permanent', false)->pluck('id')->all();
    }
}
