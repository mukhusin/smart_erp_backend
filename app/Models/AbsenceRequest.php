<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AbsenceRequest extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'employee_id', 'last_updated_by',
        'from_date', 'to_date', 'from_time', 'to_time',
        'number_of_days', 'description', 'address', 'has_per_diem', 'status',
    ];

    protected $casts = ['has_per_diem' => 'boolean', 'from_date' => 'date', 'to_date' => 'date'];

    public function employee()      { return $this->belongsTo(Employee::class); }
    public function lastUpdatedBy() { return $this->belongsTo(Employee::class, 'last_updated_by'); }
}
