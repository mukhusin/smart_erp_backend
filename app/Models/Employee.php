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
        'probation_start','probation_period','probation_end','updated_by','has_annual_leave','attendance_code','has_attendance',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'employment_date'  => 'date',
        'termination_date' => 'date',
        'probation_start'  => 'date',
        'probation_end'    => 'date',
        'has_annual_leave' => 'boolean',
        'has_attendance'   => 'boolean',
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

    /** Active staff: not left and with an active contract. Interns and residents (placements only) are not staff. */
    public function scopeActive($query)
    {
        return $query->where('employment_status', '!=', 'terminated')->whereHas('contracts', fn ($c) => $c->where('is_active', true));
    }

    /** People who work duties: active staff plus interns and residents with an active placement. */
    public function scopeOnDuty($query)
    {
        return $query->where('employment_status', '!=', 'terminated')
            ->where(fn ($q) => $q->whereHas('contracts', fn ($c) => $c->where('is_active', true))->orWhereHas('placements', fn ($p) => $p->where('status', 'active')));
    }

    /** Current staff (not left, not interns) with no active or waiting contract. */
    public function scopeWithoutContract($query)
    {
        return $query->staff()->where('employment_status', '!=', 'terminated')
            ->whereDoesntHave('contracts', fn ($c) => $c->whereIn('status', ['active', 'waiting']));
    }

    /** Active staff whose contract ends within the renewal window and who have no next contract waiting. */
    public function scopeContractEnding($query)
    {
        $soon = now()->addDays(\Modules\Hr\Support\EmployeeOptions::RENEWAL_WINDOW_DAYS)->toDateString();

        return $query->active()->whereHas('contracts', fn ($c) => $c->where('is_active', true)->whereDate('end_date', '<=', $soon))
            ->whereDoesntHave('contracts', fn ($c) => $c->where('status', 'waiting'));
    }

    /** Active staff with a licence (latest of its type) expired or ending within the renewal window. */
    public function scopeLicenceExpiring($query)
    {
        $soon = now()->addDays(\Modules\Hr\Support\EmployeeOptions::RENEWAL_WINDOW_DAYS)->toDateString();

        return $query->active()->whereHas('licences', fn ($l) => $l->whereDate('end_date', '<=', $soon)
            ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('employee_licences as n')->whereColumn('n.employee_id', 'employee_licences.employee_id')
                ->whereColumn('n.professional_licence_id', 'employee_licences.professional_licence_id')->whereColumn('n.end_date', '>', 'employee_licences.end_date')));
    }

    /** Active staff whose account invite has not been accepted yet. */
    public function scopeInvitePending($query)
    {
        return $query->active()->whereHas('user', fn ($u) => $u->whereNull('email_verified_at'));
    }

    /** Staff records, whatever their status; interns and residents are not staff. */
    public function scopeStaff($query)
    {
        return $query->where(fn ($q) => $q->whereNull('emp_type')->orWhereNotIn('emp_type', ['internship', 'resident']));
    }

    public function activeContract(){ return $this->hasOne(EmployeeContract::class)->where('is_active', true)->latest('id'); }
    public function waitingContract(){ return $this->hasOne(EmployeeContract::class)->where('status', 'waiting')->latest('id'); }
    public function licences()      { return $this->hasMany(EmployeeLicence::class); }
    public function placements()    { return $this->hasMany(EmployeePlacement::class); }
    public function patientId()     { return $this->hasOne(EmployeePatientId::class)->latest('id'); }
    public function renewals()      { return $this->hasMany(ContractRenewal::class)->latest('id'); }
    public function currentPlacement(){ return $this->hasOne(EmployeePlacement::class)->whereIn('status', ['active', 'upcoming'])->orderByRaw("status = 'active' desc")->orderBy('start_date'); }
    public function bankAccounts()  { return $this->hasMany(EmployeeBankAccount::class); }
    public function bankAccount()   { return $this->hasOne(EmployeeBankAccount::class)->where('is_active', true)->latest('id'); }
    public function documents()     { return $this->hasMany(EmployeeDocument::class); }
    public function supervisors()   { return $this->hasMany(EmpSupervisor::class)->where('status','active'); }
    public function supervisions()  { return $this->hasMany(EmpSupervisor::class)->latest('from_date')->latest('id'); }
    public function currentSupervisor() { return $this->hasOne(EmpSupervisor::class)->where('status', 'active')->latest('id'); }
    public function team()          { return $this->hasMany(EmpSupervisor::class, 'supervisor_id')->where('status', 'active'); }
}
