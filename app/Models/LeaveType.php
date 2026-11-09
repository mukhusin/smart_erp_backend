<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'code', 'gender', 'description',
        'with_pay', 'max_days', 'is_active',
    ];

    protected $casts = ['with_pay' => 'boolean', 'is_active' => 'boolean'];
    public function leaves()  { return $this->hasMany(EmpLeave::class); }
}
