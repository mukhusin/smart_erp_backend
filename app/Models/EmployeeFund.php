<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeFund extends Model
{
    protected $fillable = ['employee_id', 'amount', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
