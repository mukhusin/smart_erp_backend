<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\MessageRecipient;
use App\Models\Messaging;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Sms\PhoneNumber;
use Modules\Core\Sms\SmsService;
use Modules\Hr\Jobs\SendStaffMessageJob;
use Modules\Hr\Mail\StaffMessageMail;
use Modules\Hr\Support\EmployeeScope;

/** Bulk SMS, email or in-app notice to staff within the sender's employee scope, now or at a set time. */
class SendStaffMessages
{
    public const PLACEHOLDERS = ['{first_name}', '{name}', '{employee_no}'];

    public function __construct(private readonly EmployeeScope $scope, private readonly SmsService $sms) {}

    public function employees(array $data, User $user): Builder
    {
        $query = $this->scope->apply(Employee::query()->active(), $user);

        $query = match ($data['recipient_type']) {
            Messaging::RECIPIENT_INDIVIDUAL => $query->whereIn('id', $data['employee_ids'] ?? []),
            Messaging::RECIPIENT_DEPARTMENT => $query->where(fn ($q) => $q->where('department_id', $data['department_id'])->orWhere('section_id', $data['department_id'])),
            Messaging::RECIPIENT_SECTION => $query->where('section_id', $data['section_id']),
            Messaging::RECIPIENT_BRANCH => $query->where('company_branch_id', $data['branch_id']),
            default => $query,
        };

        return $query->when($data['emp_type'] ?? null, fn ($q, $t) => $q->where('emp_type', $t));
    }

    /** @return array{total: int, reachable: int, missing: list<string>} */
    public function preview(array $data, User $user): array
    {
        $employees = $this->employees($data, $user)->with('user')->get();
        $missing = $employees->filter(fn ($e) => ! $this->address($e, $data['type']));

        return [
            'total' => $employees->count(),
            'reachable' => $employees->count() - $missing->count(),
            'missing' => $missing->take(50)->map(fn ($e) => trim("{$e->fname} {$e->lname}").($e->employee_no ? " ({$e->employee_no})" : ''))->values()->all(),
        ];
    }

    public function create(array $data, User $user): Messaging
    {
        if ($data['type'] === Messaging::TYPE_SMS && ! $this->sms->enabled()) {
            throw ValidationException::withMessages(['type' => 'SMS is turned off. Turn it on in Administration → SMS settings.']);
        }

        $employees = $this->employees($data, $user)->with('user')->get();
        $reachable = $employees->filter(fn ($e) => $this->address($e, $data['type']));
        if ($reachable->isEmpty()) {
            throw ValidationException::withMessages(['recipient_type' => 'None of these employees has '.$this->needs($data['type']).'.']);
        }

        $scheduled = ! empty($data['scheduled_at']) ? now()->parse($data['scheduled_at']) : now();
        $popup = ! empty($data['popup']);
        if ($popup) {
            if ($data['type'] !== Messaging::TYPE_NOTICE) {
                throw ValidationException::withMessages(['popup' => 'Only in-app notices can show as a pop-up.']);
            }
            $this->ensureNoOtherPopup(now()->parse($data['popup_from']), now()->parse($data['popup_until']));
        }
        $max = (int) config('sms.max_length', 120);

        $messaging = DB::transaction(function () use ($data, $user, $employees, $reachable, $scheduled, $max, $popup) {
            $messaging = Messaging::create([
                'type' => $data['type'],
                'campaign' => 'staff_message',
                'from_address' => $data['type'] === Messaging::TYPE_SMS ? $this->sms->settings()->sender_id : ($data['type'] === Messaging::TYPE_EMAIL ? config('mail.from.address') : null),
                'subject' => $data['subject'] ?? Str::limit($data['message'], 80),
                'message' => $data['message'],
                'scheduled_at' => $scheduled,
                'recipient_type' => $data['recipient_type'],
                'filters' => array_filter(['branch_id' => $data['branch_id'] ?? null, 'emp_type' => $data['emp_type'] ?? null, 'employee_ids' => $data['employee_ids'] ?? null]),
                'department_id' => $data['department_id'] ?? null,
                'section_id' => $data['section_id'] ?? null,
                'skipped_count' => $employees->count() - $reachable->count(),
                'created_by' => $user->id,
                'status' => Messaging::STATUS_PENDING,
                'popup_active' => $popup,
                'popup_from' => $popup ? $data['popup_from'] : null,
                'popup_until' => $popup ? $data['popup_until'] : null,
            ]);

            foreach ($reachable as $employee) {
                $text = $this->personalise($data['message'], $employee);
                $messaging->recipients()->create([
                    'uuid' => (string) Str::uuid(),
                    'employee_id' => $employee->id,
                    'recipient_address' => $this->address($employee, $data['type']),
                    'individual_message' => $data['type'] === Messaging::TYPE_SMS ? mb_substr($text, 0, $max) : $text,
                    'status' => MessageRecipient::STATUS_PENDING,
                ]);
            }

            return $messaging;
        });

        if ($scheduled->lte(now())) {
            SendStaffMessageJob::dispatch($messaging->id);
        }

        return $messaging->refresh();
    }

    /** Switch a notice's pop-up on or off, or change when it shows. Only one pop-up may show at a time. */
    public function setPopup(Messaging $messaging, bool $active, ?string $from, ?string $until): Messaging
    {
        if ($messaging->type !== Messaging::TYPE_NOTICE || $messaging->status === Messaging::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['popup' => 'Only in-app notices that were not cancelled can show as a pop-up.']);
        }
        $from = $from ? now()->parse($from) : $messaging->popup_from;
        $until = $until ? now()->parse($until) : $messaging->popup_until;
        if ($active) {
            if (! $from || ! $until || $until->lte($from)) {
                throw ValidationException::withMessages(['popup_until' => 'Choose when the pop-up starts and an end after it.']);
            }
            if ($until->isPast()) {
                throw ValidationException::withMessages(['popup_until' => 'The end is already past.']);
            }
            $this->ensureNoOtherPopup($from, $until, $messaging->id);
        }
        $messaging->update(['popup_active' => $active, 'popup_from' => $from, 'popup_until' => $until]);

        return $messaging->refresh();
    }

    /** The pop-up showing now to an employee (they must be a recipient of the notice). */
    public function currentPopup(?int $employeeId): ?Messaging
    {
        if (! $employeeId) {
            return null;
        }

        return Messaging::with('creator')->where('campaign', 'staff_message')->where('type', Messaging::TYPE_NOTICE)->where('popup_active', true)
            ->where('status', '!=', Messaging::STATUS_CANCELLED)->where('popup_from', '<=', now())->where('popup_until', '>', now())
            ->whereHas('recipients', fn ($q) => $q->where('employee_id', $employeeId))->latest('popup_from')->first();
    }

    private function ensureNoOtherPopup(\DateTimeInterface $from, \DateTimeInterface $until, ?int $except = null): void
    {
        $other = Messaging::where('campaign', 'staff_message')->where('popup_active', true)->where('status', '!=', Messaging::STATUS_CANCELLED)
            ->when($except, fn ($q) => $q->whereKeyNot($except))->where('popup_from', '<', $until)->where('popup_until', '>', $from)->first();
        if ($other) {
            throw ValidationException::withMessages(['popup_from' => "Only one pop-up can show at a time: \"{$other->subject}\" shows ".$other->popup_from->format('d M H:i').' – '.$other->popup_until->format('d M H:i').'. Switch it off or choose other dates.']);
        }
    }

    public function cancel(Messaging $messaging): Messaging
    {
        if ($messaging->status !== Messaging::STATUS_PENDING || $messaging->scheduled_at->lte(now())) {
            throw ValidationException::withMessages(['message' => 'Only messages still waiting for their send time can be cancelled.']);
        }
        $messaging->update(['status' => Messaging::STATUS_CANCELLED]);
        $messaging->recipients()->where('status', MessageRecipient::STATUS_PENDING)->update(['status' => Messaging::STATUS_CANCELLED]);

        return $messaging;
    }

    public function deliver(Messaging $messaging): void
    {
        $claimed = Messaging::whereKey($messaging->id)->where('status', Messaging::STATUS_PENDING)->update(['status' => 'Sending']);
        if (! $claimed) {
            return;
        }
        $messaging->refresh();

        match ($messaging->type) {
            Messaging::TYPE_SMS => $this->sms->deliver($messaging),
            Messaging::TYPE_EMAIL => $this->each($messaging, fn (MessageRecipient $r, string $company) => Mail::to($r->recipient_address)
                ->send(new StaffMessageMail($messaging->subject ?? 'Message', $r->individual_message ?? $messaging->message, $company))),
            Messaging::TYPE_NOTICE => $this->each($messaging, fn (MessageRecipient $r) => $r->employee?->user?->notify(
                new Notice($messaging->subject ?? 'Message', $r->individual_message ?? $messaging->message, null, 'megaphone'))),
        };

        $failed = $messaging->recipients()->where('status', MessageRecipient::STATUS_FAILED)->count();
        $total = $messaging->recipients()->count();
        $messaging->update(['status' => $failed && $failed === $total ? Messaging::STATUS_FAILED : Messaging::STATUS_SENT, 'sent_at' => now()]);
    }

    /** Due scheduled messages of every company. */
    public function sendDue(): int
    {
        $due = Messaging::withoutCompanyScope()->where('campaign', 'staff_message')->where('status', Messaging::STATUS_PENDING)
            ->where('scheduled_at', '<=', now())->get(['id', 'company_id']);
        foreach ($due as $m) {
            SendStaffMessageJob::dispatch($m->id);
        }

        return $due->count();
    }

    private function each(Messaging $messaging, \Closure $send): void
    {
        $company = $messaging->company?->name ?? config('app.name');
        foreach ($messaging->recipients()->with('employee.user')->where('status', MessageRecipient::STATUS_PENDING)->get() as $r) {
            try {
                $send($r, $company);
                $r->update(['status' => MessageRecipient::STATUS_SENT]);
            } catch (\Throwable $e) {
                Log::warning('Staff message failed', ['messaging_id' => $messaging->id, 'recipient' => $r->id, 'error' => $e->getMessage()]);
                $r->update(['status' => MessageRecipient::STATUS_FAILED, 'response' => Str::limit($e->getMessage(), 250)]);
            }
        }
    }

    private function address(Employee $employee, string $type): ?string
    {
        return match ($type) {
            Messaging::TYPE_SMS => $employee->phone_number ? PhoneNumber::tanzania($employee->phone_number) : null,
            Messaging::TYPE_EMAIL => $employee->user?->email ?: $employee->email ?: null,
            Messaging::TYPE_NOTICE => $employee->user && $employee->user->is_active ? ($employee->user->username ?? $employee->user->email) : null,
            default => null,
        };
    }

    private function needs(string $type): string
    {
        return match ($type) {
            Messaging::TYPE_SMS => 'a valid phone number',
            Messaging::TYPE_EMAIL => 'an email address',
            default => 'an active user account',
        };
    }

    private function personalise(string $message, Employee $employee): string
    {
        return str_replace(self::PLACEHOLDERS, [
            $employee->fname,
            trim(preg_replace('/\s+/', ' ', "{$employee->fname} {$employee->mname} {$employee->lname}")),
            (string) $employee->employee_no,
        ], $message);
    }
}
