<?php

namespace Modules\Fleet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class StatusChange extends Model
{
    protected $table = 'fleet_status_changes';

    protected $fillable = ['fleet_unit_id', 'from', 'to', 'note', 'changed_by'];

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
