<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The company (tenant) the current request or job works for.
 *
 * In requests it is the logged-in user's company. Platform admins and guests have none.
 * There is no fallback to "the first company": no company means no company data.
 */
class CompanyContext
{
    private ?int $forcedId = null;
    private bool $forced = false;

    private ?Company $company = null;
    private ?int $companyRequestKey = null;

    public function id(): ?int
    {
        if ($this->forced) {
            return $this->forcedId;
        }

        return $this->currentUser()?->company_id;
    }

    public function get(): ?Company
    {
        $id = $this->id();
        if (! $id) {
            return null;
        }
        // Cache for the current request only (the service lives longer under tests/queue workers).
        $requestKey = spl_object_id(request());
        if ($this->company?->id !== $id || $this->companyRequestKey !== $requestKey) {
            $this->company = Company::find($id);
            $this->companyRequestKey = $requestKey;
        }

        return $this->company;
    }

    /** Run code for one company (scheduled commands, queued jobs, platform actions). */
    public function run(Company|int $company, Closure $callback): mixed
    {
        [$previousForced, $previousId] = [$this->forced, $this->forcedId];
        $this->forced = true;
        $this->forcedId = $company instanceof Company ? $company->id : $company;
        $teamBefore = getPermissionsTeamId();
        setPermissionsTeamId($this->forcedId);

        try {
            return $callback();
        } finally {
            [$this->forced, $this->forcedId] = [$previousForced, $previousId];
            setPermissionsTeamId($teamBefore);
        }
    }

    private function currentUser(): ?User
    {
        // The request's guard (web session for the SPA; sanctum once auth:sanctum ran).
        // Before the auth middleware, token clients are resolved through Sanctum when a bearer token is sent.
        $user = Auth::user() ?? (request()->bearerToken() ? Auth::guard('sanctum')->user() : null);

        return $user instanceof User ? $user : null;
    }
}
