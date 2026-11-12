<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpLeaveFile extends Model
{
    protected $fillable = ['emp_leave_id', 'file_path', 'file_name', 'size'];
}
