<?php

namespace Modules\Appraisals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AppraisalScore extends Model
{
    public const RATERS = ['self' => 'Self', 'supervisor' => 'Supervisor', 'hod' => 'Head of department', 'hr' => 'HR'];

    protected $fillable = ['appraisal_id', 'rater', 'part', 'appraisal_competency_id', 'score', 'comment', 'user_id'];

    protected $casts = ['score' => 'float'];

    public function appraisal()
    {
        return $this->belongsTo(Appraisal::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
