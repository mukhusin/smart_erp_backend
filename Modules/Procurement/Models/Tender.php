<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class Tender extends Model
{
    use Auditable, BelongsToCompany;

    public const DOCUMENT_TYPES = ['goods' => 'Goods', 'health_goods' => 'Health goods', 'fuel' => 'Fuel', 'small_works' => 'Small works', 'large_works' => 'Large works',
        'design_build' => 'Design and build', 'plant' => 'Plant supply and install', 'consultancy_lump' => 'Consultancy (lump sum)', 'consultancy_time' => 'Consultancy (time based)',
        'services' => 'Non-consultancy services', 'framework' => 'Framework', 'prequalification' => 'Prequalification', 'eoi' => 'Expression of interest', 'disposal' => 'Disposal'];

    public const STATUS_LABELS = ['draft' => 'Draft', 'document_review' => 'Document waiting for approval', 'published' => 'Open for bids', 'opened' => 'Bids opened, evaluating',
        'evaluation_review' => 'Evaluation report waiting for approval', 'evaluated' => 'Evaluated, award to prepare', 'award_review' => 'Award waiting for the tender board',
        'standstill' => 'Notice of intention to award (standstill)', 'awarded' => 'Awarded', 'contracted' => 'Contract made', 'cancelled' => 'Cancelled', 'rejected' => 'Returned'];

    protected $fillable = ['company_id', 'number', 'purchase_requisition_id', 'procurement_method_id', 'title', 'document_type', 'instructions', 'method_reason', 'closing_at', 'validity_days',
        'bid_security_amount', 'status', 'opened_at', 'opened_by', 'opening_attendees', 'evaluation_summary', 'negotiation_minutes', 'standstill_until', 'decision_comment', 'cancel_reason', 'created_by'];

    protected $casts = ['closing_at' => 'datetime', 'opened_at' => 'datetime', 'standstill_until' => 'date', 'bid_security_amount' => 'float'];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function method()
    {
        return $this->belongsTo(ProcurementMethod::class, 'procurement_method_id');
    }

    public function lines()
    {
        return $this->hasMany(TenderLine::class)->orderBy('lot')->orderBy('id');
    }

    public function criteria()
    {
        return $this->hasMany(TenderCriterion::class)->orderBy('sort')->orderBy('id');
    }

    public function clarifications()
    {
        return $this->hasMany(TenderClarification::class)->orderBy('id');
    }

    public function members()
    {
        return $this->hasMany(TenderMember::class)->orderBy('id');
    }

    public function bids()
    {
        return $this->hasMany(TenderBid::class)->orderBy('id');
    }

    public function awards()
    {
        return $this->hasMany(TenderAward::class)->orderBy('lot');
    }

    public function complaints()
    {
        return $this->hasMany(TenderComplaint::class)->orderBy('id');
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function isOpened(): bool
    {
        return $this->opened_at !== null;
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    /** @return list<string> */
    public function lots(): array
    {
        return $this->lines->pluck('lot')->unique()->values()->all();
    }
}
