<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A month's salary file uploaded by payroll (the HRMS way): one row per employee with what was paid. */
class UploadedPayroll extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'uploaded_by', 'month', 'year', 'file_name'];

    public function rows()
    {
        return $this->hasMany(UploadedPayrollRow::class);
    }

    public function uploader()
    {
        return $this->belongsTo(Employee::class, 'uploaded_by')->withoutGlobalScopes();
    }
}
