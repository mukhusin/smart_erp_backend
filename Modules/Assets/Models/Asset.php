<?php

namespace Modules\Assets\Models;

use App\Models\CompanyBranch;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\FundSource;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\WorkflowInstance;

class Asset extends Model
{
    use Auditable, BelongsToCompany;

    public const CLASSES = ['equipment' => 'Equipment and machines', 'medical' => 'Medical equipment', 'computer' => 'Computers and IT', 'furniture' => 'Furniture and fittings',
        'vehicle' => 'Motor vehicle', 'land' => 'Land', 'building' => 'Building', 'road' => 'Road or structure', 'software' => 'Software and licences', 'other' => 'Other'];

    public const DETAILS = [
        'vehicle' => ['registration' => 'Registration number', 'make' => 'Make', 'model' => 'Model', 'body_type' => 'Body type', 'engine_number' => 'Engine number', 'chassis_number' => 'Chassis number', 'engine_size' => 'Engine size (cc)', 'year' => 'Year of manufacture'],
        'land' => ['plot_number' => 'Plot number', 'title_deed' => 'Title deed number', 'area' => 'Area (m²)', 'land_use' => 'Land use', 'rights_from' => 'Rights of occupancy from', 'rights_to' => 'Rights of occupancy to'],
        'building' => ['floors' => 'Floors', 'building_use' => 'Use', 'land_asset' => 'On land (asset number)'],
        'road' => ['road_type' => 'Type', 'start_point' => 'Start point', 'end_point' => 'End point', 'length' => 'Length (km)'],
        'software' => ['licence_key' => 'Licence or key', 'expires_on' => 'Licence expires', 'seats' => 'Users / seats'],
    ];

    public const ACQUISITION = ['purchase' => 'Bought', 'donation' => 'Received as a donation', 'transfer' => 'Transferred in from another organisation', 'opening' => 'Opening balance (already owned)'];

    public const CONDITIONS = ['new' => 'New', 'very_good' => 'Very good', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'very_poor' => 'Very poor'];

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'rejected' => 'Returned', 'registered' => 'Registered (not in use)', 'active' => 'In use',
        'stored' => 'In store', 'suspended' => 'Suspended', 'disposal_pending' => 'Waiting for disposal', 'disposed' => 'Disposed'];

    public const ON_BOOKS = ['registered', 'active', 'stored', 'suspended', 'disposal_pending'];

    protected $fillable = ['company_id', 'number', 'name', 'description', 'item_category_id', 'item_id', 'asset_class', 'details', 'acquisition_type', 'acquisition_date', 'in_use_date',
        'cost', 'opening_depreciation', 'useful_life_months', 'residual_value', 'fund_source_id', 'supplier_id', 'goods_receipt_id', 'serial_number', 'tag', 'company_branch_id',
        'department_id', 'employee_id', 'location', 'condition', 'status', 'dep_from', 'dep_value', 'dep_until', 'dep_charged_to', 'confirmed_year', 'journal_entry_id', 'created_by',
        'decision_comment', 'approved_at'];

    protected $casts = ['details' => 'array', 'acquisition_date' => 'date', 'in_use_date' => 'date', 'cost' => 'float', 'opening_depreciation' => 'float', 'residual_value' => 'float',
        'dep_from' => 'date', 'dep_value' => 'float', 'dep_until' => 'date', 'dep_charged_to' => 'date', 'approved_at' => 'datetime'];

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function fundSource()
    {
        return $this->belongsTo(FundSource::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function entries()
    {
        return $this->hasMany(AssetEntry::class)->orderBy('date')->orderBy('id');
    }

    public function assignments()
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('from_date')->orderByDesc('id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function onBooks(): bool
    {
        return in_array($this->status, self::ON_BOOKS, true);
    }

    /** Land, work in progress and assets without a useful life are never depreciated. */
    public function depreciable(): bool
    {
        return $this->asset_class !== 'land' && $this->category?->type !== 'wip' && (int) $this->useful_life_months > 0;
    }

    /** @return array{cost: float, depreciation: float, book_value: float} */
    public function valueAt(?string $date = null): array
    {
        $q = $this->entries()->getQuery()->reorder()->when($date, fn ($q) => $q->whereDate('date', '<=', $date));
        $cost = round((float) (clone $q)->sum('cost'), 2);
        $dep = round((float) (clone $q)->sum('depreciation'), 2);

        return ['cost' => $cost, 'depreciation' => $dep, 'book_value' => round($cost - $dep, 2)];
    }

    public function holderName(): ?string
    {
        return $this->employee ? trim($this->employee->fname.' '.$this->employee->lname) : $this->department?->name;
    }
}
