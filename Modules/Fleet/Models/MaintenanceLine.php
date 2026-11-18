<?php

namespace Modules\Fleet\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceLine extends Model
{
    public const KINDS = ['parts' => 'Parts', 'labour' => 'Labour', 'other' => 'Other'];

    protected $table = 'fleet_maintenance_lines';

    protected $fillable = ['fleet_maintenance_id', 'kind', 'description', 'quantity', 'unit_cost', 'amount'];

    protected $casts = ['quantity' => 'float', 'unit_cost' => 'float', 'amount' => 'float'];
}
