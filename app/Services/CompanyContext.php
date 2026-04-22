<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

class CompanyContext
{
    protected ?Company $company = null;

    public function resolve(): ?Company
    {
        if ($this->company) {
            return $this->company;
        }

        if (Auth::check() && Auth::user()->company_id) {
            $this->company = Auth::user()->company;
            return $this->company;
        }

        // Fallback: first active company
        $this->company = Company::where('is_active', true)->first();
        return $this->company;
    }

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function id(): ?int
    {
        return $this->resolve()?->id;
    }

    public function get(): ?Company
    {
        return $this->resolve();
    }
}
