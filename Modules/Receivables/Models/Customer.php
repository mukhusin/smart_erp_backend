<?php

namespace Modules\Receivables\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['patient' => 'Patient', 'insurer' => 'Insurer', 'company' => 'Company', 'donor' => 'Donor', 'individual' => 'Individual', 'other' => 'Other'];

    protected $fillable = ['company_id', 'code', 'name', 'kind', 'tin', 'phone', 'email', 'address', 'contact_person', 'terms_days', 'credit_limit', 'on_hold', 'is_active'];

    protected $casts = ['terms_days' => 'integer', 'credit_limit' => 'float', 'on_hold' => 'boolean', 'is_active' => 'boolean'];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }
}
