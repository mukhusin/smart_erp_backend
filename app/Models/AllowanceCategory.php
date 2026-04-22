<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowanceCategory extends Model
{
    protected $fillable = [
        'company_id', 'category', 'duration', 'code',
        'claimed', 'deduct_leave', 'other_absence', 'is_active',
    ];

    protected $casts = [
        'claimed' => 'boolean', 'deduct_leave' => 'boolean',
        'other_absence' => 'boolean', 'is_active' => 'boolean',
    ];

    public function company()             { return $this->belongsTo(Company::class); }
    public function departmentAllowances(){ return $this->hasMany(DepartmentAllowance::class, 'category_id'); }
}
