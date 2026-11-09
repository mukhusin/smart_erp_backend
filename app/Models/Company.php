<?php

namespace App\Models;


use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'short_name', 'registration_number', 'tin',
        'phone', 'email', 'website', 'address', 'city', 'country',
        'logo', 'login_background', 'primary_color', 'secondary_color', 'currency', 'fiscal_year_start', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /** Already limited to this company, so the current-company filter is not needed here. */
    public function branches()
    {
        return $this->hasMany(CompanyBranch::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function modules()
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function getLoginBackgroundUrlAttribute(): ?string
    {
        return $this->login_background ? asset('storage/' . $this->login_background) : null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }
}
