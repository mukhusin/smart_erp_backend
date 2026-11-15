<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EmployeeFund extends Model
{
    use Auditable;
    protected $fillable = ['employee_id', 'amount', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
