<?php

namespace Modules\Admin\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recorded change to a user's access (see the migration for the approval-ready design). */
class AccessChange extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';

    protected $fillable = ['company_id', 'user_id', 'type', 'before', 'after', 'reason', 'status', 'requested_by', 'decided_by', 'applied_at'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'applied_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
