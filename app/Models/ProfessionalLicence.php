<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProfessionalLicence extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'provider_name', 'short_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function employeeLicences()
    {
        return $this->hasMany(EmployeeLicence::class);
    }
}
