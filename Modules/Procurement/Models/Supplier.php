<?php

namespace Modules\Procurement\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['company' => 'Company', 'individual' => 'Individual', 'government' => 'Public body', 'ngo' => 'NGO'];

    public const SPECIAL_GROUPS = ['women' => 'Women', 'youth' => 'Youth', 'disability' => 'Persons with disability', 'elderly' => 'Elderly', 'local' => 'Local supplier'];

    protected $fillable = ['company_id', 'code', 'name', 'kind', 'tin', 'vrn', 'registration_number', 'phone', 'email', 'address', 'contact_person', 'special_group',
        'categories', 'payment_terms_days', 'is_active', 'blacklisted', 'blacklist_reason', 'blacklisted_until', 'created_by'];

    protected $casts = ['categories' => 'array', 'is_active' => 'boolean', 'blacklisted' => 'boolean', 'blacklisted_until' => 'date', 'payment_terms_days' => 'integer'];

    public function bankAccounts()
    {
        return $this->hasMany(SupplierBankAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function documents()
    {
        return $this->hasMany(SupplierDocument::class)->orderBy('expires_on');
    }

    public function isBlocked(): bool
    {
        return $this->blacklisted && (! $this->blacklisted_until || $this->blacklisted_until->gte(today()));
    }

    /** Usable for orders and payments: active, not blacklisted. */
    public function usable(): bool
    {
        return $this->is_active && ! $this->isBlocked();
    }
}
