<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\SendUserInvite;
use Modules\Core\Mail\StaffInviteMail;

/**
 * Gives an employee a user account with only the basic "employee" role. Other roles are given by the company admin
 * (Administration → Users), through the approval workflow. The user sets their own password from the invite email.
 */
class CreateEmployeeAccount
{
    public const DEFAULT_ROLE = 'employee';

    public function __construct(private readonly CompanyContext $context, private readonly SendUserInvite $invites) {}

    public function create(Employee $employee, ?string $username = null): User
    {
        if ($employee->user_id) {
            throw ValidationException::withMessages(['employee' => 'This employee already has a user account.']);
        }
        if (! $employee->email) {
            throw ValidationException::withMessages(['email' => 'An email is needed to send the invite.']);
        }

        $email = Str::lower($employee->email);
        $username = $username ?: $email;
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Another user already has this email.']);
        }
        if (User::where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => 'This username is taken.']);
        }

        $user = User::create([
            'company_id' => $employee->company_id,
            'company_branch_id' => $employee->company_branch_id,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make(Str::password(40)),
            'is_active' => true,
        ]);
        $user->assignRole(self::DEFAULT_ROLE);
        $employee->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    public function invite(Employee $employee): bool
    {
        $user = $employee->user;
        if (! $user || $user->email_verified_at) {
            return false;
        }

        return $this->invites->handle($user, fn (string $url) => new StaffInviteMail($this->context->get(), $user, $url));
    }
}
