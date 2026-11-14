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
        'logo', 'login_background', 'primary_color', 'secondary_color', 'theme', 'currency', 'fiscal_year_start', 'is_active',
    ];

    /**
     * Where the brand colors are used, and how: plain (none), tinted (light wash) or solid; cards: plain, accent (top stripe)
     * or tinted (header); home page module tiles: brand colors or each module's own color. The first option is the default.
     */
    public const THEME_AREAS = [
        'sidebar' => ['plain', 'tinted', 'solid'],
        'navbar' => ['plain', 'tinted', 'solid'],
        'tables' => ['plain', 'tinted', 'solid'],
        'cards' => ['plain', 'accent', 'tinted'],
        'tiles' => ['brand', 'module'],
    ];

    protected $casts = ['is_active' => 'boolean', 'theme' => 'array'];

    /** @return array{sidebar: string, navbar: string, tables: string, cards: string, tiles: string} */
    public function themeSettings(): array
    {
        $saved = $this->theme ?? [];

        return collect(self::THEME_AREAS)->map(fn ($options, $area) => in_array($saved[$area] ?? null, $options, true) ? $saved[$area] : $options[0])->all();
    }

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
        return $this->login_background ? '/storage/' . $this->login_background : null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? '/storage/' . $this->logo : null;
    }
}
