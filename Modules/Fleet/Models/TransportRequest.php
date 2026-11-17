<?php

namespace Modules\Fleet\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class TransportRequest extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved, waiting for a vehicle', 'allocated' => 'Vehicle allocated', 'completed' => 'Done',
        'rejected' => 'Not approved', 'returned' => 'Returned by transport', 'cancelled' => 'Cancelled'];

    protected $table = 'fleet_transport_requests';

    protected $fillable = ['company_id', 'number', 'requested_by', 'employee_id', 'department_id', 'purpose', 'from_location', 'destination', 'departs_at', 'returns_at', 'passengers',
        'passenger_names', 'cargo', 'cargo_weight', 'vehicle_type_id', 'status', 'decision_comment', 'decided_at'];

    protected $casts = ['departs_at' => 'datetime', 'returns_at' => 'datetime', 'passengers' => 'integer', 'cargo_weight' => 'float', 'decided_at' => 'datetime'];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function type()
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class)->orderBy('id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
