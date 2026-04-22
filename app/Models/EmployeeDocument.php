<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeDocument extends Model {
    protected $fillable = ['employee_id','file_name','file_path','file_type','description'];
    public function employee() { return $this->belongsTo(Employee::class); }
    public function getFileUrlAttribute(): string { return asset('storage/' . $this->file_path); }
}
