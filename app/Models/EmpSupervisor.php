<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmpSupervisor extends Model {
    protected $fillable = ['employee_id','supervisor_id','from_date','to_date','status'];
    public function employee()   { return $this->belongsTo(Employee::class); }
    public function supervisor() { return $this->belongsTo(Employee::class, 'supervisor_id'); }
}
