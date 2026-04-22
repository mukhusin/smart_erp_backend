<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanProvider extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'short_name', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
    public function loans()   { return $this->hasMany(EmployeeLoan::class); }
}
