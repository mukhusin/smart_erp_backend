<?php

namespace Modules\Receivables\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReceivableSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'receivable_account_id', 'default_terms_days'];

    protected $casts = ['default_terms_days' => 'integer'];

    public static function current(): self
    {
        return static::firstOrNew([], ['default_terms_days' => 30]);
    }
}
