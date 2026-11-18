<?php

namespace Modules\Workflow\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkflowActionLog extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'workflow_action_id', 'workflow_instance_id', 'workflow_instance_step_id', 'event', 'status', 'detail'];
}
