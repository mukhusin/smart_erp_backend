<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeLoan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_id', 'loan_provider_id', 'updated_by',
        'date_of_loan', 'amount', 'instalment', 'returned', 'remain',
        'end_date', 'is_active', 'status',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function employee()     { return $this->belongsTo(Employee::class); }
    public function loanProvider() { return $this->belongsTo(LoanProvider::class); }
    public function updatedBy()    { return $this->belongsTo(Employee::class, 'updated_by'); }
    public function company()      { return $this->belongsTo(Company::class); }
    public function histories()    { return $this->hasMany(LoanHistory::class); }
}
