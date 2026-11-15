<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Heslb extends Model
{
    use Auditable;
    protected $fillable = ['employee_id', 'contribution_no', 'emp_rate', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
