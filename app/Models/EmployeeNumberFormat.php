<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmployeeNumberFormat extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'separator', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function segments()
    {
        return $this->hasMany(EmployeeNumberFormatSegment::class, 'format_id')->orderBy('position');
    }

    public function contractTypes()
    {
        return $this->hasMany(NatureOfContract::class, 'employee_number_format_id');
    }
}
