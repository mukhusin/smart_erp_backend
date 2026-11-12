<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmpLeave;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmpLeave */
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $self = $request->routeIs('self.*');
        $type = $this->leaveType;
        $step = $this->status === EmpLeave::PENDING ? $this->workflow?->steps?->firstWhere('status', 'pending') : null;

        return [
            'id' => $this->id,
            'employee' => $self || ! $this->employee ? null : [
                'id' => $this->employee->id, 'name' => $this->employee->full_name, 'employee_no' => $this->employee->employee_no, 'department' => $this->employee->department?->name,
            ],
            'leave_type' => $type ? ['id' => $type->id, 'name' => $type->name, 'code' => $type->code, 'kind' => $type->kind, 'color' => $type->color, 'with_pay' => (bool) $type->with_pay] : null,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'days' => (float) $this->days,
            'requested_start_date' => $this->requested_start_date?->toDateString(),
            'requested_end_date' => $this->requested_end_date?->toDateString(),
            'days_requested' => (float) $this->days_requested,
            'leave_year' => (int) $this->leave_year,
            /** pending, approved, on_leave, reported, rejected, cancelled */
            'status' => $this->status,
            'status_label' => EmpLeave::STATUS_LABELS[$this->status] ?? $this->status,
            /** employee or hr */
            'source' => $this->source,
            'description' => $this->description,
            /** Compassionate leave: deceased_name, relationship, death_date. @var array<string, string>|null */
            'details' => $this->details,
            'leave_address' => $this->leave_address,
            'leave_phone' => $this->leave_phone,
            'report_date' => $this->report_date?->toDateString(),
            'waiting_for' => $step?->name,
            'decision_comment' => $this->decision_comment,
            /** @var list<array{id: int, name: string, url: string}> */
            'files' => $this->files->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->file_name, 'url' => route($self ? 'self.leave.file' : 'leave.requests.file', [$this->id, $f->id], false),
            ])->values()->all(),
            'can_withdraw' => $this->status === EmpLeave::PENDING || ($this->status === EmpLeave::APPROVED && $this->start_date->isFuture()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
