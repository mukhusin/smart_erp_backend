<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierDocument extends Model
{
    use BelongsToCompany;

    public const TYPES = ['tax_clearance' => 'Tax clearance', 'business_licence' => 'Business licence', 'registration' => 'Certificate of registration', 'tin' => 'TIN certificate',
        'vat' => 'VAT certificate', 'professional' => 'Professional registration', 'other' => 'Other'];

    protected $fillable = ['company_id', 'supplier_id', 'type', 'number', 'issued_on', 'expires_on', 'path', 'file_name', 'uploaded_by'];

    protected $casts = ['issued_on' => 'date', 'expires_on' => 'date'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function state(): string
    {
        if (! $this->expires_on) {
            return 'valid';
        }

        return $this->expires_on->lt(today()) ? 'expired' : ($this->expires_on->lte(today()->addDays(30)) ? 'expiring' : 'valid');
    }
}
