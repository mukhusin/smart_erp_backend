<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HeadOfDepartment extends Model {
    protected $fillable = ['employee_id','department_id','level','change_reason','is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function employee()   { return $this->belongsTo(Employee::class); }
    public function department() { return $this->belongsTo(Department::class); }
}
