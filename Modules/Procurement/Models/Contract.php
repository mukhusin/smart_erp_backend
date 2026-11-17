<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class Contract extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['lump_sum' => 'Lump sum', 'time_based' => 'Time based', 'percentage' => 'Percentage', 'retainer' => 'Retainer', 'running' => 'Running (ordered as needed)',
        'indefinite' => 'Indefinite delivery', 'framework' => 'Framework agreement'];

    public const CATEGORIES = ['goods' => 'Goods (paid by delivery)', 'works' => 'Works (paid by certificate)', 'services' => 'Services (paid by certificate)', 'consultancy' => 'Consultancy (paid by certificate)'];

    public const SECURITY = ['bank_guarantee' => 'Bank guarantee', 'insurance_bond' => 'Insurance bond', 'cheque' => 'Banker\'s cheque', 'cash' => 'Cash deposit'];

    public const STATUS_LABELS = ['draft' => 'Draft', 'vetting' => 'Being vetted', 'rejected' => 'Returned', 'active' => 'Signed, running', 'completed' => 'Completed', 'closed' => 'Closed', 'terminated' => 'Terminated'];

    protected $fillable = ['company_id', 'number', 'tender_id', 'tender_award_id', 'purchase_requisition_id', 'supplier_id', 'title', 'type', 'category', 'start_date', 'end_date', 'currency',
        'exchange_rate', 'value', 'retention_percent', 'damages_percent_per_day', 'damages_cap_percent', 'security_type', 'security_amount', 'security_reference', 'security_expiry',
        'percent_complete', 'status', 'signed_on', 'warned_on', 'decision_comment', 'close_note', 'created_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'exchange_rate' => 'float', 'value' => 'float', 'retention_percent' => 'float', 'damages_percent_per_day' => 'float',
        'damages_cap_percent' => 'float', 'security_amount' => 'float', 'security_expiry' => 'date', 'signed_on' => 'date', 'warned_on' => 'date'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function lines()
    {
        return $this->hasMany(ContractLine::class)->orderBy('id');
    }

    public function milestones()
    {
        return $this->hasMany(ContractMilestone::class)->orderBy('due_date')->orderBy('id');
    }

    public function certificates()
    {
        return $this->hasMany(ContractCertificate::class)->orderBy('id');
    }

    public function variations()
    {
        return $this->hasMany(ContractVariation::class)->orderBy('id');
    }

    public function progress()
    {
        return $this->hasMany(ContractProgress::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function orders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function byCertificate(): bool
    {
        return $this->category !== 'goods';
    }

    /** @return array{value: float, varied: float, ordered: float, certified: float, retained: float, paid: float, balance: float} */
    public function figures(): array
    {
        $varied = round((float) $this->variations()->where('status', 'approved')->sum('amount'), 2);
        $ordered = round((float) $this->orders()->whereNotIn('status', ['draft', 'rejected', 'cancelled'])->sum('total'), 2);
        $certs = $this->certificates()->where('status', 'approved')->get();
        $certified = round($certs->sum('gross'), 2);
        $retained = round($certs->sum('retention') - (float) $this->milestones()->where('kind', 'retention')->whereIn('status', ['requested', 'paid'])->sum('amount'), 2);
        $paidIds = PaymentRequestIds::forContract($this);
        $paid = round((float) \Modules\Payments\Models\PaymentListItem::where('status', 'paid')
            ->whereHas('voucher', fn ($v) => $v->whereIn('payment_request_id', $paidIds))->sum('amount'), 2);
        $used = $this->byCertificate() ? $certified : $ordered;

        return ['value' => $this->value, 'varied' => $varied, 'ordered' => $ordered, 'certified' => $certified, 'retained' => $retained, 'paid' => $paid,
            'balance' => round($this->value + $varied - $used, 2)];
    }
}
