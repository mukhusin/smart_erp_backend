<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeExperience extends Model {
    protected $fillable = ['employee_id','company_name','position','start_date','end_date','salary','description'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
