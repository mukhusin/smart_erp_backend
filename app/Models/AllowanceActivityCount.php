<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** The number of activities (e.g. theatre cases) on a day for an activity allowance, entered by hand or fetched. */
class AllowanceActivityCount extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'allowance_category_id', 'date', 'count', 'source', 'status', 'error', 'raw', 'entered_by'];

    protected $casts = ['date' => 'date', 'count' => 'float'];

    protected array $auditIgnore = ['raw'];

    public function category()
    {
        return $this->belongsTo(AllowanceCategory::class, 'allowance_category_id');
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function usable(): bool
    {
        return $this->status === 'ok' && $this->count !== null;
    }
}
