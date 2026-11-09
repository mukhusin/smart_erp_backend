<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppraisalRatingScale extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id','scale','minimum_percent','maximum_percent','description'];

    protected $casts = [
        'minimum_percent' => 'decimal:2',
        'maximum_percent' => 'decimal:2',
    ];
}
