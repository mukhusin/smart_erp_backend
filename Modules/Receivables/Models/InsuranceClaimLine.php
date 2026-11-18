<?php

namespace Modules\Receivables\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceClaimLine extends Model
{
    public const RESOLUTIONS = ['written_off' => 'Written off', 'rebilled' => 'Billed to the patient / member', 'resubmit' => 'To claim again'];

    protected $fillable = ['insurance_claim_id', 'invoice_id', 'claimed', 'approved', 'rejected', 'rejection_reason', 'resolution', 'credit_note_id', 'rebill_invoice_id'];

    protected $casts = ['claimed' => 'float', 'approved' => 'float', 'rejected' => 'float'];

    public function claim()
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function rebillInvoice()
    {
        return $this->belongsTo(Invoice::class, 'rebill_invoice_id');
    }
}
