<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $fillable = [
        'company_id','reference_no','date','description','status','created_by',
    ];

    protected $casts = ['date' => 'date'];

    const STATUSES = ['draft','posted','reversed'];

    public function company() { return $this->belongsTo(Company::class); }
    public function lines()   { return $this->hasMany(JournalEntryLine::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function getTotalDebitAttribute(): float
    {
        return $this->lines->sum('debit');
    }

    public function getTotalCreditAttribute(): float
    {
        return $this->lines->sum('credit');
    }
}
