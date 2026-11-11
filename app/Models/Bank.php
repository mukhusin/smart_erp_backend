<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'code', 'swift_code', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function accounts() { return $this->hasMany(EmployeeBankAccount::class); }
}
