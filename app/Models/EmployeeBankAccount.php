<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class EmployeeBankAccount extends Model {
    use Auditable;
    protected $fillable = ['employee_id','bank_id','account_name','branch','account_number','is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function employee() { return $this->belongsTo(Employee::class); }
    public function bank()     { return $this->belongsTo(Bank::class); }
}
