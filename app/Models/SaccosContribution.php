<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccosContribution extends Model
{
    protected $fillable = ['saccos_member_id', 'payroll_sheet_detail_id', 'amount', 'date_contributed'];
    protected $casts    = ['date_contributed' => 'date'];

    public function saccosMember()       { return $this->belongsTo(SaccosMember::class); }
    public function payrollSheetDetail() { return $this->belongsTo(PayrollSheetDetail::class); }
}
