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
        $status = $this->state();

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
            /** active | upcoming | ended | pending | rejected | cancelled */
            'status' => $status,
            'source' => $this->source,
            'suspend_giver' => (bool) $this->suspend_giver,
            'decision_comment' => $this->decision_comment,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
