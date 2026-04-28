<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccosMember extends Model
{
    protected $fillable = ['employee_id', 'amount', 'total_contribution', 'from_date', 'is_active'];
    protected $casts    = ['is_active' => 'boolean', 'from_date' => 'date'];

    public function employee()      { return $this->belongsTo(Employee::class); }
    public function contributions() { return $this->hasMany(SaccosContribution::class); }
}
