<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowanceBatchLine extends Model
{
    protected $fillable = ['allowance_batch_id', 'employee_id', 'items', 'amount_per_item', 'amount', 'department_allowance_id', 'daily_allowance_id'];

    protected $casts = ['items' => 'float', 'amount_per_item' => 'float', 'amount' => 'float'];

    public function batch()
    {
        return $this->belongsTo(AllowanceBatch::class, 'allowance_batch_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
