<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryScale extends Model {
    use SoftDeletes;
    protected $fillable = ['company_id','job_position_id','name','minimum','maximum','description'];
    protected $casts = ['minimum' => 'decimal:2', 'maximum' => 'decimal:2'];

    public function company() { return $this->belongsTo(Company::class); }
    public function jobPosition() { return $this->belongsTo(JobPosition::class); }
}
