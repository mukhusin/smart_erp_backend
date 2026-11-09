<?php

namespace Modules\Admin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Models\RoleHandover;

/**
 * @mixin RoleHandover
 */
class HandoverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();
        $status = match (true) {
            $this->revoked_at !== null => 'ended',
            $this->starts_at > $now => 'upcoming',
            $this->ends_at !== null && $this->ends_at <= $now => 'ended',
            default => 'active',
        };

        return [
            'id' => $this->id,
            /** @var array{id: int, name: string} */
            'role' => ['id' => $this->role->id, 'name' => $this->role->name],
            /** @var array{id: int, name: string}|null */
            'from_user' => $this->fromUser ? ['id' => $this->fromUser->id, 'name' => $this->fromUser->display_name] : null,
            /** @var array{id: int, name: string} */
            'to_user' => ['id' => $this->toUser->id, 'name' => $this->toUser->display_name],
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'reason' => $this->reason,
            /** active | upcoming | ended */
            'status' => $status,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
