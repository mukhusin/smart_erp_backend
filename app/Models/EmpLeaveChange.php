<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A change to a leave request's dates during approval, or the return recorded by HR. */
class EmpLeaveChange extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'emp_leave_id', 'user_id', 'step_name', 'kind', 'before', 'after', 'comment'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
