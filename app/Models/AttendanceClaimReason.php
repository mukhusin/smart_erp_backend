<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceClaimReason extends Model
{
    protected $fillable = ['company_id', 'reason', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
    public function claims()  { return $this->hasMany(AttendanceClaim::class, 'claim_reason_id'); }
}
