<?php

namespace Modules\Audit\Actions;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Models\AuditEngagement;
use Modules\Audit\Support\Auditors;
use Modules\Audit\Workflow\AuditEngagementProcess;
use Modules\Core\Actions\SendUserInvite;
use Modules\Core\Mail\StaffInviteMail;
use Modules\Core\Support\Numbering;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/** Access for an external audit firm: approved, then a read-only account limited to the audited period that switches itself off. */
class ManageEngagements
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly SendUserInvite $invite, private readonly CompanyContext $context) {}

    public function create(array $data, User $by): AuditEngagement
    {
        if (User::where('email', $data['email'])->where(fn ($q) => $q->where('company_id', '!=', $this->context->id())->orWhereNull('company_id')
            ->orWhere(fn ($q) => $q->where('company_id', $this->context->id())->whereDoesntHave('roles', fn ($r) => $r->where('name', Auditors::EXTERNAL))))->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to another account; use the auditor\'s own work email.']);
        }

        return DB::transaction(function () use ($data, $by) {
            $e = AuditEngagement::create(['number' => Numbering::next(AuditEngagement::class, 'AUD'), 'status' => 'pending', 'created_by' => $by->id,
                'payslips' => (bool) ($data['payslips'] ?? false)] + collect($data)->only(['firm', 'auditor_name', 'email', 'period_from', 'period_to', 'access_until', 'scope_note'])->all());
            if (! $this->workflow->start(AuditEngagementProcess::KEY, $e, $by)) {
                $this->approved($e);
            }

            return $e->refresh();
        });
    }

    public function approved(AuditEngagement $e): void
    {
        DB::transaction(function () use ($e) {
            $user = User::where('email', $e->email)->where('company_id', $e->company_id)->first()
                ?? User::create(['email' => $e->email, 'username' => $this->username($e), 'company_id' => $e->company_id, 'is_active' => true, 'password' => Str::random(40)]);
            $user->forceFill(['full_name' => $e->auditor_name.' ('.$e->firm.')', 'is_active' => true, 'access_expires_at' => $e->access_until->copy()->endOfDay()])->save();
            setPermissionsTeamId($e->company_id);
            $user->syncRoles([Auditors::EXTERNAL]);
            $user->syncPermissions($e->payslips ? ['payroll.view'] : []);
            $e->update(['status' => 'active', 'user_id' => $user->id, 'decided_at' => now()]);
            $company = $this->context->get() ?? \App\Models\Company::find($e->company_id);
            $this->invite->handle($user, fn (string $url) => new StaffInviteMail($company, $user, $url));
        });
    }

    private function username(AuditEngagement $e): string
    {
        $base = Str::slug(Str::before($e->email, '@'), '_') ?: 'auditor';
        $name = $base;
        for ($i = 2; User::where('username', $name)->exists(); $i++) {
            $name = $base.'_'.$i;
        }

        return $name;
    }

    public function rejected(AuditEngagement $e, ?string $comment, bool $withdrawn): void
    {
        $e->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
    }

    public function withdraw(AuditEngagement $e, User $by): AuditEngagement
    {
        if ($e->status !== 'pending' || $e->created_by !== $by->id) {
            throw ValidationException::withMessages(['engagement' => 'Only the person who asked can withdraw it while it waits.']);
        }
        $instance = $e->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($e, null, true);

        return $e->refresh();
    }

    /** Ends the access now (or when the end date passes): the account is switched off. */
    public function end(AuditEngagement $e): AuditEngagement
    {
        if ($e->status !== 'active') {
            throw ValidationException::withMessages(['engagement' => 'This access is not active.']);
        }
        DB::transaction(function () use ($e) {
            $e->user?->forceFill(['is_active' => false, 'access_expires_at' => now()])->save();
            $e->update(['status' => 'ended']);
        });

        return $e->refresh();
    }

    public function expire(): int
    {
        $n = 0;
        foreach (AuditEngagement::where('status', 'active')->whereDate('access_until', '<', today())->get() as $e) {
            $this->end($e);
            $n++;
        }

        return $n;
    }
}
