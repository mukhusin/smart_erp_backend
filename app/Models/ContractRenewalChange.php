<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A change to the proposed terms of a renewal made during approval. */
class ContractRenewalChange extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'contract_renewal_id', 'user_id', 'step_name', 'before', 'after', 'comment'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
