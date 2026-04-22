<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AppraisalRatingScale extends Model
{
    protected $fillable = ['company_id','scale','minimum_percent','maximum_percent','description'];

    protected $casts = [
        'minimum_percent' => 'decimal:2',
        'maximum_percent' => 'decimal:2',
    ];

    public function company() { return $this->belongsTo(Company::class); }
}
