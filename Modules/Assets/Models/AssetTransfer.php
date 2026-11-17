<?php

namespace Modules\Assets\Models;

use App\Models\CompanyBranch;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;
use Modules\Workflow\Models\WorkflowInstance;

class AssetTransfer extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['internal' => 'Within the organisation', 'external' => 'To another organisation'];

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Done', 'returned' => 'Returned', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'asset_id', 'kind', 'permanent', 'date', 'return_date', 'to_branch_id', 'to_department_id', 'to_employee_id', 'to_location',
        'to_organisation', 'previous', 'reason', 'status', 'returned_at', 'reminded_on', 'journal_entry_id', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['permanent' => 'boolean', 'date' => 'date', 'return_date' => 'date', 'previous' => 'array', 'returned_at' => 'datetime', 'reminded_on' => 'date', 'decided_at' => 'datetime'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function toBranch()
    {
        return $this->belongsTo(CompanyBranch::class, 'to_branch_id');
    }

    public function toDepartment()
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function toEmployee()
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function destination(): string
    {
        if ($this->kind === 'external') {
            return (string) $this->to_organisation;
        }

        return collect([$this->toEmployee ? trim($this->toEmployee->fname.' '.$this->toEmployee->lname) : null, $this->toDepartment?->name, $this->toBranch?->name, $this->to_location])->filter()->join(' · ');
    }
}
