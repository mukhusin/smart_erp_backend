<?php

namespace Modules\Workflow\Models;

use App\Models\CompanyBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'process', 'company_branch_id', 'is_active', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('step_order');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }
}
