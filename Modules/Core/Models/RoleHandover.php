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

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    /** Roles employees cannot hand over themselves. */
    public const NOT_SELF = ['company-admin', 'internal-auditor', 'external-auditor', 'employee'];

    protected $fillable = ['company_id', 'role_id', 'from_user_id', 'to_user_id', 'starts_at', 'ends_at', 'reason', 'status', 'source', 'suspend_giver', 'emp_leave_id',
        'decided_at', 'decision_comment', 'created_by', 'revoked_at', 'revoked_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime', 'decided_at' => 'datetime', 'suspend_giver' => 'boolean'];

    /** In effect right now (approved, started, not ended or revoked). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'approved')->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function leave(): BelongsTo
    {
        return $this->belongsTo(\App\Models\EmpLeave::class, 'emp_leave_id');
    }

    public function workflow()
    {
        return $this->morphOne(\Modules\Workflow\Models\WorkflowInstance::class, 'subject')->latestOfMany();
    }

    /** active | upcoming | ended | pending | rejected | cancelled */
    public function state(): string
    {
        $now = now();

        return match (true) {
            $this->status !== 'approved' => $this->status,
            $this->revoked_at !== null => 'ended',
            $this->starts_at > $now => 'upcoming',
            $this->ends_at !== null && $this->ends_at <= $now => 'ended',
            default => 'active',
        };
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
