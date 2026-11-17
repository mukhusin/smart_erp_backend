<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Budgeting\Models\Budget;
use Modules\Workflow\Models\WorkflowInstance;

class PaymentRequest extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = [
        'draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned',
        'cancelled' => 'Cancelled', 'vouchered' => 'Voucher prepared', 'paid' => 'Paid', 'retired' => 'Retired', 'recovering' => 'Being recovered from salary', 'recovered' => 'Recovered from salary',
    ];

    public const KINDS = ['payment' => 'Payment', 'imprest' => 'Imprest (staff advance)'];

    protected $fillable = ['company_id', 'number', 'kind', 'holder_employee_id', 'retire_by', 'budget_id', 'department_id', 'title', 'purpose', 'needed_by', 'total', 'status', 'over_budget', 'source_type', 'source_id', 'supplier_id',
        'requested_by', 'submitted_at', 'decision_comment', 'decided_at'];

    protected $casts = ['needed_by' => 'date', 'retire_by' => 'date', 'total' => 'float', 'over_budget' => 'boolean', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function supplier()
    {
        return $this->belongsTo(\Modules\Procurement\Models\Supplier::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function lines()
    {
        return $this->hasMany(PaymentRequestLine::class)->orderBy('id');
    }

    public function payees()
    {
        return $this->hasMany(PaymentRequestPayee::class)->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(PaymentAttachment::class)->whereNull('imprest_retirement_id')->orderBy('id');
    }

    public function holder()
    {
        return $this->belongsTo(\App\Models\Employee::class, 'holder_employee_id');
    }

    public function retirements()
    {
        return $this->hasMany(ImprestRetirement::class)->orderBy('id');
    }

    public function recoveries()
    {
        return $this->hasMany(ImprestRecovery::class)->orderBy('id');
    }

    public function isImprest(): bool
    {
        return $this->kind === 'imprest';
    }

    public function overdue(): bool
    {
        return $this->isImprest() && $this->status === 'paid' && $this->retire_by && $this->retire_by->lt(today());
    }

    public function vouchers()
    {
        return $this->hasMany(PaymentVoucher::class)->orderBy('id');
    }

    public function activeVoucher(): ?PaymentVoucher
    {
        return $this->vouchers->whereIn('status', ['pending', 'approved', 'paid'])->last();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
