<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Models\TaskActivity;
use Modules\Workflow\Models\WorkflowInstance;

class StoreRequisition extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved, ready to issue', 'rejected' => 'Returned',
        'cancelled' => 'Cancelled', 'partial' => 'Partly issued', 'issued' => 'Issued'];

    protected $fillable = ['company_id', 'number', 'store_id', 'department_id', 'task_activity_id', 'purpose', 'needed_by', 'status', 'requested_by', 'decision_comment', 'submitted_at', 'decided_at'];

    protected $casts = ['needed_by' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function activity()
    {
        return $this->belongsTo(TaskActivity::class, 'task_activity_id');
    }

    public function lines()
    {
        return $this->hasMany(StoreRequisitionLine::class)->orderBy('id');
    }

    public function issues()
    {
        return $this->hasMany(StockDocument::class)->where('kind', 'issue')->orderBy('id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
