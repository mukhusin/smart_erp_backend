<?php

namespace Modules\Planning\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StrategicPlan extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'];

    protected $fillable = ['company_id', 'title', 'start_year', 'end_year', 'vision', 'mission', 'status', 'created_by'];

    protected $casts = ['start_year' => 'integer', 'end_year' => 'integer'];

    public function objectives()
    {
        return $this->hasMany(StrategicObjective::class)->orderBy('sort_order')->orderBy('id');
    }

    public function annualPlans()
    {
        return $this->hasMany(AnnualPlan::class);
    }
}
