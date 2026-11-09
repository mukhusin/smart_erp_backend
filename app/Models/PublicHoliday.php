<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'title', 'day_number', 'month_number', 'is_active', 'updated_by'];
    protected $casts = ['is_active' => 'boolean'];
    public function updatedByEmp(){ return $this->belongsTo(Employee::class, 'updated_by'); }
}
