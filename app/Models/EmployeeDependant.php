<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDependant extends Model
{
    protected $fillable = [
        'employee_id', 'fullname', 'profile_image', 'gender', 'address', 'date_of_birth', 'birth_certificate',
        'birth_certificate_name', 'phone', 'email', 'relationship', 'is_next_of_kin',
    ];

    protected $casts = ['is_next_of_kin' => 'boolean', 'date_of_birth' => 'date'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
