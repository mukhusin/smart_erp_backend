<?php

namespace Modules\Hr\Actions;

use App\Models\AttendanceClaim;
use App\Models\AttendanceTransa;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Workflow\AttendanceClaimProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Attendance claims (HRMS lost-hours claims): the employee explains a day with lost time (forgot to punch, official
 * duty…); once approved through the "Attendance claim" workflow the lost hours are forgiven and not deducted.
 */
class ManageAttendanceClaims
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    /** @param array{claim_reason_id?: int|null, description: string, hours?: float|null} $data */
    public function claim(AttendanceTransa $day, array $data, User $by): AttendanceClaim
    {
        if ($day->lost_hour <= 0) {
            throw ValidationException::withMessages(['day' => 'This day has no lost time to claim.']);
        }
        if (AttendanceClaim::where('attendance_transa_id', $day->id)->whereIn('claim_status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['day' => 'This day already has a claim.']);
        }
        $hours = min((float) ($data['hours'] ?? $day->lost_hour), (float) $day->lost_hour);
        $claim = AttendanceClaim::create([
            'attendance_transa_id' => $day->id, 'employee_id' => $day->employee_id, 'claim_reason_id' => $data['claim_reason_id'] ?? null,
            'claim_date' => $day->transaction_date->toDateString(), 'lost_hour' => $day->lost_hour, 'lost_amount' => $day->lost_amount, 'hr_accepted' => $hours,
            'set_lost_hr_zero' => $hours >= $day->lost_hour, 'description' => $data['description'], 'claim_status' => 'pending', 'requested_by' => $by->id,
        ]);
        $day->update(['is_claimed' => true]);
        if (! $this->workflow->start(AttendanceClaimProcess::KEY, $claim, $by)) {
            $this->approve($claim);
        }

        return $claim->refresh();
    }

    public function approve(AttendanceClaim $claim): void
    {
        $claim->update(['claim_status' => 'approved', 'approved_at' => now(), 'approved_by' => auth()->user()?->employee?->id]);
        $day = $claim->transaction;
        if ($day) {
            $day->update(['accepted_hour' => (float) $day->accepted_hour + (float) $claim->hr_accepted]);
            app(ManageAttendance::class)->compileDay($day->employee, $day->transaction_date);
        }
        $claim->employee?->user?->notify(new Notice('Attendance claim approved', "Lost time on {$claim->claim_date->format('d M Y')} is forgiven.", '/self-service/attendance', 'fingerprint'));
    }

    public function reject(AttendanceClaim $claim, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $claim->update(['claim_status' => $withdrawn ? 'cancelled' : 'rejected', 'comment' => $comment]);
        $claim->transaction?->update(['is_claimed' => false]);
        if (! $withdrawn) {
            $claim->employee?->user?->notify(new Notice('Attendance claim not approved', "Your claim for {$claim->claim_date->format('d M Y')} was not approved.".($comment ? " Reason: {$comment}" : ''), '/self-service/attendance', 'fingerprint'));
        }
    }
}
