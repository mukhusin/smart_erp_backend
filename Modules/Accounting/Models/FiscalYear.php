<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'status'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function periods()
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('number');
    }

    public static function containing($date): ?self
    {
        return static::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
    }
}
