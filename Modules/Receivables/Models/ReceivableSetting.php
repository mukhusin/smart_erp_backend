<?php

namespace Modules\Receivables\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReceivableSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'receivable_account_id', 'default_terms_days', 'credit_check', 'credit_overdue_days', 'claims_enabled'];

    protected $casts = ['default_terms_days' => 'integer', 'credit_overdue_days' => 'integer', 'claims_enabled' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['default_terms_days' => 30, 'credit_check' => 'off', 'claims_enabled' => false]);
    }
}
