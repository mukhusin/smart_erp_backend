<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model {
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id','company_branch_id','department_id','section_id','job_position_id','user_id',
        'employee_no','fname','mname','lname','profile_image','gender','date_of_birth',
        'phone_number','place_of_birth','home_address','email','tin','nationality',
        'id_number','id_type','marital_status','emp_title','employment_date',
        'place_of_recruitment','emp_type','emp_category','termination_date',
        'nssf_no','nhif_no','wcf_no','employment_status','contract_id','contract_status',
        'probation_start','probation_period','probation_end','updated_by','has_annual_leave',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'employment_date'  => 'date',
        'termination_date' => 'date',
        'probation_start'  => 'date',
        'probation_end'    => 'date',
        'has_annual_leave' => 'boolean',
    ];

    public function getFullNameAttribute(): string {
        return trim("{$this->fname} " . ($this->mname ? "{$this->mname} " : '') . $this->lname);
    }

    public function getProfileImageUrlAttribute(): string {
        return $this->profile_image
            ? '/storage/' . $this->profile_image
            : 'https://ui-avatars.com/api/?name=' . urlencode($this->full_name) . '&background=0d6efd&color=fff&size=128';
    }
    public function branch()        { return $this->belongsTo(CompanyBranch::class, 'company_branch_id'); }
    public function department()    { return $this->belongsTo(Department::class); }
    public function section()       { return $this->belongsTo(Department::class, 'section_id'); }
    public function jobPosition()   { return $this->belongsTo(JobPosition::class); }
    public function user()          { return $this->belongsTo(User::class); }
    public function qualifications(){ return $this->hasMany(EmployeeQualification::class); }
    public function experiences()   { return $this->hasMany(EmployeeExperience::class); }
    public function dependants()    { return $this->hasMany(EmployeeDependant::class); }
    public function contracts()     { return $this->hasMany(EmployeeContract::class); }
    public function activeContract(){ return $this->hasOne(EmployeeContract::class)->where('is_active', true)->latest('id'); }
    public function waitingContract(){ return $this->hasOne(EmployeeContract::class)->where('status', 'waiting')->latest('id'); }
    public function licences()      { return $this->hasMany(EmployeeLicence::class); }
    public function bankAccounts()  { return $this->hasMany(EmployeeBankAccount::class); }
    public function documents()     { return $this->hasMany(EmployeeDocument::class); }
    public function supervisors()   { return $this->hasMany(EmpSupervisor::class)->where('status','active'); }
}
