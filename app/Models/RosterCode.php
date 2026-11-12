<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RosterCode extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'duty', 'description',
        'can_punch', 'pass_day', 'start_time', 'end_time', 'work_hour', 'break_minutes', 'color', 'is_active',
    ];

    protected $casts = ['can_punch' => 'boolean', 'pass_day' => 'boolean', 'work_hour' => 'float', 'break_minutes' => 'integer', 'is_active' => 'boolean'];
}
