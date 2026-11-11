<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    protected $fillable = ['employee_id', 'file_name', 'file_path', 'file_type', 'size', 'mime_type', 'description', 'uploaded_by'];

    protected $casts = ['size' => 'integer'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function uploadedBy() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
