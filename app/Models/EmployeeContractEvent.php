<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeContractEvent extends Model
{
    protected $fillable = ['employee_contract_id', 'user_id', 'action', 'status', 'comment', 'changes'];

    protected $casts = ['changes' => 'array'];

    public function contract()
    {
        return $this->belongsTo(EmployeeContract::class, 'employee_contract_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
