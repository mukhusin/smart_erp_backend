<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RosterCode extends Model
{
    protected $fillable = [
        'company_id', 'code', 'duty', 'description',
        'can_punch', 'pass_day', 'start_time', 'end_time', 'work_hour',
    ];

    protected $casts = ['can_punch' => 'boolean', 'pass_day' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
}
