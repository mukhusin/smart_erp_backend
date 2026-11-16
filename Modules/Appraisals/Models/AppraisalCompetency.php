<?php

namespace Modules\Appraisals\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppraisalCompetency extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'description', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
