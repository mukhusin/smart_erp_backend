<?php

namespace Modules\Planning\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Models\TaskActivity;

class StrategicObjective extends Model
{
    use Auditable;

    protected $fillable = ['strategic_plan_id', 'code', 'title', 'description', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function plan()
    {
        return $this->belongsTo(StrategicPlan::class, 'strategic_plan_id');
    }

    public function activities()
    {
        return $this->hasMany(TaskActivity::class);
    }

    public function label(): string
    {
        return trim(($this->code ? $this->code.' ' : '').$this->title);
    }
}
