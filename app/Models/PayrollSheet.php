<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSheet extends Model
{
    protected $fillable = [
        'company_id', 'name', 'month', 'year', 'payroll_from_date', 'payroll_to_date',
        'number_of_days', 'payroll_cost', 'hr_approved', 'payroll_approved',
        'director_approved', 'status', 'hr_officer', 'payroll_officer', 'director', 'approved_by',
    ];

    protected $casts = [
        'hr_approved' => 'boolean', 'payroll_approved' => 'boolean', 'director_approved' => 'boolean',
    ];

    public function company()       { return $this->belongsTo(Company::class); }
    public function details()       { return $this->hasMany(PayrollSheetDetail::class); }
    public function hrOfficer()     { return $this->belongsTo(Employee::class, 'hr_officer'); }
    public function payrollOfficer(){ return $this->belongsTo(Employee::class, 'payroll_officer'); }
    public function directorEmp()   { return $this->belongsTo(Employee::class, 'director'); }
    public function approvedBy()    { return $this->belongsTo(Employee::class, 'approved_by'); }
}
