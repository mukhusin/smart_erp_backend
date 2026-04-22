<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'company_id','parent_id','code','name','type','description','is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    const TYPES = ['asset','liability','equity','revenue','expense'];

    public function company()   { return $this->belongsTo(Company::class); }
    public function parent()    { return $this->belongsTo(Account::class, 'parent_id'); }
    public function children()  { return $this->hasMany(Account::class, 'parent_id'); }
    public function journalLines() { return $this->hasMany(JournalEntryLine::class); }
}
