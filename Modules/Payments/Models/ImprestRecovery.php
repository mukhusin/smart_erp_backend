<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\OtherDeduction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ImprestRecovery extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['active' => 'Being recovered', 'completed' => 'Recovered', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'payment_request_id', 'deduction_ids', 'amount', 'months', 'start_month', 'recovered', 'status', 'reason', 'created_by'];

    protected $casts = ['deduction_ids' => 'array', 'amount' => 'float', 'recovered' => 'float', 'months' => 'integer', 'start_month' => 'date'];

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function entries()
    {
        return $this->hasMany(ImprestRecoveryEntry::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deductions()
    {
        return OtherDeduction::withTrashed()->whereIn('id', $this->deduction_ids ?? []);
    }

    public function remaining(): float
    {
        return round($this->amount - $this->recovered, 2);
    }
}
