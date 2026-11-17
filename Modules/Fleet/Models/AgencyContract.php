<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Models\Supplier;

/** A contract with a transport company: trips it does are paid at the rate per trip, km or day. */
class AgencyContract extends Model
{
    use Auditable, BelongsToCompany;

    public const MODES = ['trip' => 'Per trip', 'km' => 'Per km', 'day' => 'Per day'];

    protected $table = 'fleet_agency_contracts';

    protected $fillable = ['company_id', 'supplier_id', 'reference', 'mode', 'rate', 'currency', 'starts_on', 'ends_on', 'notes', 'is_active', 'created_by'];

    protected $casts = ['rate' => 'float', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'fleet_agency_contract_id');
    }

    public function validOn(string $date): bool
    {
        return $this->is_active && $this->starts_on->toDateString() <= $date && $this->ends_on->toDateString() >= $date;
    }

    public function label(): string
    {
        return trim(($this->supplier?->name ?? 'Transport company').($this->reference ? " ({$this->reference})" : ''));
    }
}
