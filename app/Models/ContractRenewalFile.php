<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractRenewalFile extends Model
{
    protected $fillable = ['contract_renewal_id', 'file_path', 'file_name', 'size'];

    public function renewal()
    {
        return $this->belongsTo(ContractRenewal::class, 'contract_renewal_id');
    }
}
