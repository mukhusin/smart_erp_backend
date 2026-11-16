<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class FiscalPeriod extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year_id', 'number', 'name', 'start_date', 'end_date', 'status', 'closed_at', 'closed_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime', 'number' => 'integer'];

    public function year()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function quarter(): int
    {
        return (int) ceil($this->number / 3);
    }
}
