<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** A contract renewal, started by HR or requested by the employee, approved through the "contract-renewal" workflow. */
class ContractRenewal extends Model
{
    use BelongsToCompany;

    public const FROM_HR = 'hr';

    public const FROM_EMPLOYEE = 'employee';

    /** Pending approval, or approved and waiting for HR to create the contract. */
    public const OPEN = ['pending', 'approved'];

    public const STATUSES = [
        'pending' => 'Waiting for approval',
        'approved' => 'Approved, contract to be created',
        'completed' => 'Renewed',
        'rejected' => 'Rejected',
        'cancelled' => 'Withdrawn',
    ];

    /** What the employee agrees to when requesting renewal (saved with the request). */
    public const AGREEMENT = 'I confirm that the information in this request is true, and I understand that renewal is subject to approval and to the company\'s terms and conditions of employment.';

    protected $fillable = [
        'company_id', 'employee_id', 'contract_id', 'source', 'status', 'comment', 'letter', 'letter_language', 'agreement', 'agreed_at', 'nature_of_contract_id', 'period',
        'basic_salary', 'new_contract_id', 'requested_by', 'decision_comment', 'decided_at',
    ];

    protected $casts = ['basic_salary' => 'decimal:2', 'period' => 'integer', 'decided_at' => 'datetime', 'agreed_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract()
    {
        return $this->belongsTo(EmployeeContract::class);
    }

    public function newContract()
    {
        return $this->belongsTo(EmployeeContract::class, 'new_contract_id');
    }

    public function natureOfContract()
    {
        return $this->belongsTo(NatureOfContract::class)->withTrashed();
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function files()
    {
        return $this->hasMany(ContractRenewalFile::class);
    }

    public function changes()
    {
        return $this->hasMany(ContractRenewalChange::class)->latest('id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function termsComplete(): bool
    {
        return $this->nature_of_contract_id && $this->period && $this->basic_salary !== null;
    }
}
