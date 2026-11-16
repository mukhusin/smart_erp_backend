<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FundSource extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['own' => 'Own revenue', 'insurance' => 'Insurance', 'government' => 'Government', 'donor' => 'Donor', 'loan' => 'Loan', 'other' => 'Other'];

    protected $fillable = ['company_id', 'code', 'name', 'kind', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
