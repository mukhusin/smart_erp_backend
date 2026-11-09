<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AttendanceClaimReason extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'reason', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function claims()  { return $this->hasMany(AttendanceClaim::class, 'claim_reason_id'); }
}
