<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','parent_id','code','name','type','description','is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    const TYPES = ['asset','liability','equity','revenue','expense'];
    public function parent()    { return $this->belongsTo(Account::class, 'parent_id'); }
    public function children()  { return $this->hasMany(Account::class, 'parent_id'); }
    public function journalLines() { return $this->hasMany(JournalEntryLine::class); }
}
