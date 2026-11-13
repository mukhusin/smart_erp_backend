<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One clock-in/out: from the device server, a CSV import or entered by hand. */
class AttendancePunch extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'emp_code', 'punch_time', 'punch_state', 'verify_type', 'area_alias', 'terminal_sn', 'terminal_alias', 'source', 'external_id', 'note', 'created_by'];

    protected $casts = ['punch_time' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
