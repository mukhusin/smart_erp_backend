<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProcurementMethod extends Model
{
    use Auditable, BelongsToCompany;

    public const SOURCING = ['direct' => 'Direct purchase', 'quotation' => 'Request for quotations', 'tender' => 'Tender (bids, opening, evaluation)', 'single' => 'Single source', 'framework' => 'Framework call-off'];

    public const EVALUATION = ['lowest' => 'Lowest evaluated price', 'technical' => 'Technical only', 'combined' => 'Technical then financial (weighted)'];

    public const CATEGORIES = ['goods' => 'Goods', 'works' => 'Works', 'services' => 'Non-consultancy services', 'consultancy' => 'Consultancy', 'disposal' => 'Disposal of assets'];

    /** Defaults for a new company; every value is editable and more methods can be added. */
    public const DEFAULTS = [
        ['code' => 'DP', 'name' => 'Direct purchase', 'sourcing' => 'direct', 'max_amount' => 1000000, 'min_bidders' => 1],
        ['code' => 'RFQ', 'name' => 'Request for quotations', 'sourcing' => 'quotation', 'max_amount' => 20000000, 'min_bidders' => 3],
        ['code' => 'OT', 'name' => 'Open tender', 'sourcing' => 'tender', 'min_amount' => 20000000, 'min_bidders' => 1, 'standstill_days' => 7, 'negotiation' => false],
        ['code' => 'RT', 'name' => 'Restricted tender (invited shortlist)', 'sourcing' => 'tender', 'min_bidders' => 3, 'standstill_days' => 7],
        ['code' => 'CS', 'name' => 'Consultant selection (technical then financial)', 'sourcing' => 'tender', 'category' => 'consultancy', 'min_bidders' => 3, 'evaluation' => 'combined', 'technical_weight' => 80, 'pass_mark' => 70, 'negotiation' => true, 'standstill_days' => 7],
        ['code' => 'SS', 'name' => 'Single source', 'sourcing' => 'single', 'min_bidders' => 1, 'needs_reason' => true, 'negotiation' => true],
        ['code' => 'FW', 'name' => 'Framework call-off', 'sourcing' => 'framework', 'min_bidders' => 1],
        ['code' => 'DT', 'name' => 'Disposal by tender (highest offer wins)', 'sourcing' => 'tender', 'category' => 'disposal', 'min_bidders' => 1, 'highest_wins' => true, 'standstill_days' => 7],
    ];

    protected $fillable = ['company_id', 'code', 'name', 'sourcing', 'category', 'min_amount', 'max_amount', 'min_bidders', 'evaluation', 'technical_weight', 'pass_mark', 'negotiation',
        'highest_wins', 'standstill_days', 'needs_reason', 'is_active'];

    protected $casts = ['min_amount' => 'float', 'max_amount' => 'float', 'negotiation' => 'boolean', 'highest_wins' => 'boolean', 'needs_reason' => 'boolean', 'is_active' => 'boolean'];

    public static function ensureDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $d) {
            static::create($d + ['evaluation' => 'lowest', 'is_active' => true]);
        }
    }

    public function allows(float $amount): bool
    {
        return ($this->min_amount === null || $amount >= $this->min_amount) && ($this->max_amount === null || $amount <= $this->max_amount);
    }
}
