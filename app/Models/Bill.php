<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','supplier_id','bill_no','issue_date','due_date',
        'subtotal','tax_amount','total_amount','paid_amount','status','notes',
    ];

    protected $casts = [
        'issue_date'   => 'date',
        'due_date'     => 'date',
        'subtotal'     => 'decimal:2',
        'tax_amount'   => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount'  => 'decimal:2',
    ];

    const STATUSES = ['draft','open','partial','paid','overdue','cancelled'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function payments() { return $this->hasMany(BillPayment::class); }

    public function getBalanceAttribute(): float
    {
        return $this->total_amount - $this->paid_amount;
    }
}
