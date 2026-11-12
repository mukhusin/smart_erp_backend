<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Workflow\Models\WorkflowInstance;

class Roster extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [self::DRAFT => 'Draft', self::PENDING => 'Waiting for approval', self::PUBLISHED => 'Published', self::REJECTED => 'Returned'];

    protected $fillable = [
        'company_id', 'department_id', 'section_id', 'title', 'type',
        'from_date', 'to_date', 'roster_year', 'prepared_by', 'approved_by',
        'status', 'is_active', 'created_by', 'submitted_at', 'published_at', 'decision_comment',
    ];

    protected $casts = [
        'is_active' => 'boolean', 'from_date' => 'date', 'to_date' => 'date', 'submitted_at' => 'datetime', 'published_at' => 'datetime',
    ];

    public function department() { return $this->belongsTo(Department::class); }
    public function section()    { return $this->belongsTo(Department::class, 'section_id'); }
    public function preparedBy() { return $this->belongsTo(Employee::class, 'prepared_by'); }
    public function approvedBy() { return $this->belongsTo(Employee::class, 'approved_by'); }
    public function creator()    { return $this->belongsTo(User::class, 'created_by'); }
    public function annualLeaves(){ return $this->hasMany(AnnualLeaveRoster::class); }
    public function duties()     { return $this->hasMany(EmployeeTimetable::class); }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REJECTED, self::PUBLISHED], true);
    }

    public function scopeDuty($query)
    {
        return $query->where('type', 'duty');
    }
}
