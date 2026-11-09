<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosition extends Model {
    use BelongsToCompany, SoftDeletes;
    protected $fillable = ['company_id','department_id','title','description','is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function department() { return $this->belongsTo(Department::class); }
    public function employees() { return $this->hasMany(Employee::class); }
    public function salaryScales() { return $this->hasMany(SalaryScale::class); }
}
