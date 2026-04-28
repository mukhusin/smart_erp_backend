<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tughe extends Model
{
    protected $fillable = ['employee_id', 'contribution_no', 'emp_rate', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
