<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequest extends Model
{
    protected $fillable = [
        'company_id','department_id','requested_by','pr_number','date','status','notes',
    ];

    protected $casts = ['date' => 'date'];

    const STATUSES = ['draft','pending','approved','rejected','ordered'];

    public function company()    { return $this->belongsTo(Company::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function requester()  { return $this->belongsTo(Employee::class, 'requested_by'); }
    public function items()      { return $this->hasMany(PurchaseRequestItem::class); }
    public function orders()     { return $this->hasMany(PurchaseOrder::class); }
}
