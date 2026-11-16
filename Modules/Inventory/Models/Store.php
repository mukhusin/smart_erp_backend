<?php

namespace Modules\Inventory\Models;

use App\Models\CompanyBranch;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['main' => 'Main store', 'sub' => 'Sub-store', 'pharmacy' => 'Pharmacy / dispensing', 'transit' => 'Goods in transit'];

    protected $fillable = ['company_id', 'code', 'name', 'company_branch_id', 'department_id', 'kind', 'location', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'store_users')->withPivot('role');
    }

    public function bins()
    {
        return $this->hasMany(StoreBin::class)->orderBy('code');
    }
}
