<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** One day's count-based allowance (e.g. theatre cases) for a team, entered by its head and approved as one batch. */
class AllowanceBatch extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved'];

    public const EDITABLE = ['draft', 'rejected'];

    protected $fillable = ['company_id', 'allowance_category_id', 'department_id', 'date', 'status', 'notes', 'created_by', 'submitted_at', 'decided_at', 'decision_comment'];

    protected $casts = ['date' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function category()
    {
        return $this->belongsTo(AllowanceCategory::class, 'allowance_category_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(AllowanceBatchLine::class);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }
}
