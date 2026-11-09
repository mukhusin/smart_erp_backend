<?php

namespace Modules\Platform\Actions;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Modules\Core\Support\DefaultRoles;
use Modules\Platform\Mail\CompanyInviteMail;

/** Emails the company admin a link to set their password. Returns false if the email could not be sent. */
class SendCompanyInvite
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Company $company): bool
    {
        $admin = $this->companyAdmin($company);
        if (! $admin?->email) {
            return false;
        }

        $token = Password::broker('invites')->createToken($admin);
        $url = rtrim(config('app.frontend_url'), '/') . '/set-password?' . http_build_query([
            'token' => $token,
            'email' => $admin->email,
        ]);

        try {
            Mail::to($admin->email)->send(new CompanyInviteMail($company, $admin, $url));
            return true;
        } catch (\Throwable $e) {
            Log::error('Company invite email failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function companyAdmin(Company $company): ?User
    {
        return $this->context->run($company, fn () => User::role(DefaultRoles::COMPANY_ADMIN)
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first());
    }
}
