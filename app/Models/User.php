<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Scopes\CompanyScope;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected string $guard_name = 'web';

    protected $fillable = [
        'email',
        'company_id', 
        'company_branch_id',
        'avatar',
        'username',
        'role_as',
        'is_active',
        'is_platform_admin',
        'otp',
        'otp_expiry',
        'verification_code',
        'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** System owner: manages client companies, belongs to none, sees no company data. */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id')->withoutGlobalScope(CompanyScope::class);
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'user_id')->withoutGlobalScope(CompanyScope::class);
    }

    /** Users have no name column: the name comes from the linked employee, otherwise the username. */
    public function getDisplayNameAttribute(): string
    {
        $employee = $this->employee;
        $name = $employee ? trim(preg_replace('/\s+/', ' ', "{$employee->fname} {$employee->mname} {$employee->lname}")) : '';

        return $name !== '' ? $name : $this->username;
    }

    /** Uploaded avatar, or null so the frontend shows initials (no external avatar service). */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? '/storage/' . $this->avatar : null;
    }
}
