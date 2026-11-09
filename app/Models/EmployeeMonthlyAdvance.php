<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeMonthlyAdvance extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_id', 'created_by',
        'amount', 'date_advanced', 'month', 'year', 'status', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function employee()  { return $this->belongsTo(Employee::class); }
    public function createdBy() { return $this->belongsTo(Employee::class, 'created_by'); }
}
