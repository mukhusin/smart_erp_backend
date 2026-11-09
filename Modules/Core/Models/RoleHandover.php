<?php

namespace Modules\Core\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/** A role handed over to another user for a period (acting / covering). */
class RoleHandover extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'role_id', 'from_user_id', 'to_user_id', 'starts_at', 'ends_at', 'reason', 'created_by', 'revoked_at', 'revoked_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];

    /** In effect right now. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
