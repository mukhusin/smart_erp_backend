<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\WorkflowInstance;

class Maintenance extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['preventive' => 'Service', 'corrective' => 'Repair', 'accident' => 'Accident repair'];

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'To inspect', 'inspection' => 'Inspection waiting for approval', 'sourcing' => 'Parts and work being bought',
        'in_repair' => 'In the workshop', 'completed' => 'Done', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    public const OPEN = ['pending', 'approved', 'inspection', 'sourcing', 'in_repair'];

    protected $table = 'fleet_maintenance';

    protected $fillable = ['company_id', 'number', 'fleet_unit_id', 'kind', 'fleet_service_plan_id', 'fleet_accident_id', 'description', 'reading', 'status', 'supplier_id', 'workshop', 'findings',
        'estimate', 'department_id', 'budget_item_id', 'purchase_requisition_id', 'started_on', 'completed_on', 'completed_reading', 'actual_cost', 'invoice_reference', 'completion_notes',
        'decision_comment', 'requested_by', 'inspected_by'];

    protected $casts = ['reading' => 'float', 'estimate' => 'float', 'started_on' => 'date', 'completed_on' => 'date', 'completed_reading' => 'float', 'actual_cost' => 'float'];

    public function unit()
    {
        return $this->belongsTo(FleetUnit::class, 'fleet_unit_id');
    }

    public function plan()
    {
        return $this->belongsTo(ServicePlan::class, 'fleet_service_plan_id');
    }

    public function accident()
    {
        return $this->belongsTo(Accident::class, 'fleet_accident_id');
    }

    public function lines()
    {
        return $this->hasMany(MaintenanceLine::class, 'fleet_maintenance_id')->orderBy('id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function workflows()
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->orderByDesc('id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
