<?php

namespace Modules\Platform\Actions;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use Modules\Core\Actions\SendUserInvite;
use Modules\Core\Support\DefaultRoles;
use Modules\Platform\Mail\CompanyInviteMail;

/** Emails the company admin a link to set their password. Returns false if the email could not be sent. */
class SendCompanyInvite
{
    public function __construct(private readonly CompanyContext $context, private readonly SendUserInvite $invite) {}

    public function handle(Company $company): bool
    {
        $admin = $this->companyAdmin($company);

        return $admin !== null && $this->invite->handle($admin, fn (string $url) => new CompanyInviteMail($company, $admin, $url));
    }

    public function companyAdmin(Company $company): ?User
    {
        return $this->context->run($company, fn () => User::role(DefaultRoles::COMPANY_ADMIN)
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first());
    }
}
