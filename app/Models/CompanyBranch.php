<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CompanyBranch extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'name', 'code', 'phone', 'email',
        'address', 'city', 'is_headquarters', 'is_active',
    ];

    protected $casts = [
        'is_headquarters' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'company_branch_id');
    }
}
