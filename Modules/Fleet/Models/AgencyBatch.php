<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Payments\Models\PaymentRequest;

class AgencyBatch extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'fleet_agency_batches';

    protected $fillable = ['company_id', 'number', 'fleet_agency_contract_id', 'from_date', 'to_date', 'total', 'payment_request_id', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'total' => 'float'];

    public function contract()
    {
        return $this->belongsTo(AgencyContract::class, 'fleet_agency_contract_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'fleet_agency_batch_id');
    }

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
