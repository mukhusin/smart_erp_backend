<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    protected $fillable = ['company_id', 'title', 'day_number', 'month_number', 'is_active', 'updated_by'];
    protected $casts = ['is_active' => 'boolean'];

    public function company()     { return $this->belongsTo(Company::class); }
    public function updatedByEmp(){ return $this->belongsTo(Employee::class, 'updated_by'); }
}
