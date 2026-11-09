<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Roster extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'department_id', 'section_id', 'title', 'type',
        'from_date', 'to_date', 'roster_year', 'prepared_by', 'approved_by',
        'status', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];
    public function department() { return $this->belongsTo(Department::class); }
    public function section()    { return $this->belongsTo(Department::class, 'section_id'); }
    public function preparedBy() { return $this->belongsTo(Employee::class, 'prepared_by'); }
    public function approvedBy() { return $this->belongsTo(Employee::class, 'approved_by'); }
    public function annualLeaves(){ return $this->hasMany(AnnualLeaveRoster::class); }
}
