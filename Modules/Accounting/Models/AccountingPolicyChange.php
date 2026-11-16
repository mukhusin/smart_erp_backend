<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AccountingPolicyChange extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'framework', 'framework_name', 'policies', 'effective_from', 'reason', 'changed_by'];

    protected $casts = ['policies' => 'array', 'effective_from' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
