<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_name', 'registration_number', 'tin',
        'phone', 'email', 'website', 'address', 'city', 'country',
        'logo', 'currency', 'fiscal_year_start', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function branches()
    {
        return $this->hasMany(CompanyBranch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }
}
