<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class ContractMilestone extends Model
{

    public const KINDS = ['advance' => 'Advance payment', 'delivery' => 'Delivery', 'certificate' => 'Certificate (works or services)', 'retention' => 'Retention release'];

    public const STATUS_LABELS = ['planned' => 'Planned', 'requested' => 'Payment requested', 'paid' => 'Paid'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    protected $fillable = ['contract_id', 'name', 'kind', 'amount', 'due_date', 'status', 'payment_request_id'];

    protected $casts = ['amount' => 'float', 'due_date' => 'date'];
}
