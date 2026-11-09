<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanProvider extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'short_name', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function loans()   { return $this->hasMany(EmployeeLoan::class); }
}
