<?php

namespace Modules\Admin\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->auditable_type,
            'type_label' => AuditLog::TYPES[$this->auditable_type] ?? ucfirst(str_replace('-', ' ', $this->auditable_type)),
            'record_id' => $this->auditable_id,
            'action' => $this->action,
            'action_label' => AuditLog::ACTIONS[$this->action] ?? ucfirst($this->action),
            /** @var list<array{field: string, label: string, old: mixed, new: mixed}> */
            'changes' => $this->changes(),
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->display_name] : null,
            'employee' => $this->employee ? ['id' => $this->employee->id, 'name' => $this->employee->full_name, 'employee_no' => $this->employee->employee_no] : null,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'url' => $this->url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
