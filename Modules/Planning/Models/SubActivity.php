<?php

namespace Modules\Planning\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Modules\Tasks\Models\TaskActivity;

class SubActivity extends Model
{
    use Auditable;

    protected $fillable = ['task_activity_id', 'code', 'title', 'output', 'quarters', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function activity()
    {
        return $this->belongsTo(TaskActivity::class, 'task_activity_id');
    }

    public function label(): string
    {
        return trim(($this->code ? $this->code.' ' : '').$this->title);
    }
}
