<?php

namespace Modules\Core\Models;

use App\Models\CompanyBranch;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A module blocked for one branch or one user inside a company. */
class ModuleLock extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'module_slug', 'company_branch_id', 'user_id', 'reason', 'locked_by'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
