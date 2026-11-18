<?php

namespace Modules\Receivables\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaim extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'submitted' => 'Submitted to payer', 'responded' => 'Payer responded', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'number', 'customer_id', 'period_from', 'period_to', 'status', 'payer_reference', 'claimed', 'approved', 'rejected', 'submitted_on',
        'responded_on', 'notes', 'created_by'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'submitted_on' => 'date', 'responded_on' => 'date', 'claimed' => 'float', 'approved' => 'float', 'rejected' => 'float'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines()
    {
        return $this->hasMany(InsuranceClaimLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
