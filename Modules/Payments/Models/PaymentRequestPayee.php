<?php

namespace Modules\Payments\Models;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;

class PaymentRequestPayee extends Model
{
    public const KINDS = ['supplier' => 'Supplier', 'staff' => 'Staff', 'other' => 'Other'];

    public const METHODS = ['bank' => 'Bank transfer', 'mobile' => 'Mobile money', 'cheque' => 'Cheque', 'cash' => 'Cash'];

    protected $fillable = ['payment_request_id', 'name', 'kind', 'employee_id', 'method', 'bank_name', 'account_name', 'account_number', 'mobile_number', 'amount', 'withholding'];

    protected $casts = ['amount' => 'float', 'withholding' => 'float'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function net(): float
    {
        return round($this->amount - $this->withholding, 2);
    }

    public function destination(): string
    {
        return match ($this->method) {
            'bank' => trim(($this->bank_name ?? '').' '.($this->account_number ?? '')),
            'mobile' => (string) $this->mobile_number,
            default => PaymentRequestPayee::METHODS[$this->method] ?? $this->method,
        };
    }
}
