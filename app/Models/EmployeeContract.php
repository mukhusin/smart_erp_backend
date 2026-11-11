<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeContract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'nature_of_contract_id', 'salary_scale_id', 'contract_no', 'basic_salary', 'period',
        'start_date', 'end_date', 'date_of_change', 'comment', 'is_active', 'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'date_of_change' => 'date',
        'basic_salary' => 'decimal:2',
        'period' => 'integer',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function natureOfContract() { return $this->belongsTo(NatureOfContract::class)->withTrashed(); }
    public function salaryScale() { return $this->belongsTo(SalaryScale::class); }
    public function events() { return $this->hasMany(EmployeeContractEvent::class)->latest('id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
}
