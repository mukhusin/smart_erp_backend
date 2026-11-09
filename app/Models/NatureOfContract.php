<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NatureOfContract extends Model {
    use BelongsToCompany, SoftDeletes;
    protected $fillable = ['company_id','contract_name','code','employment_type','has_annual_leave','has_payroll','has_overtime','has_allowance','has_emergency_fund','is_active','contract_description','template_content'];
    protected $casts = ['has_annual_leave'=>'boolean','has_payroll'=>'boolean','has_overtime'=>'boolean','has_allowance'=>'boolean','has_emergency_fund'=>'boolean','is_active'=>'boolean'];
}
