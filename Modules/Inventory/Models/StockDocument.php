<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;
use Modules\Tasks\Models\TaskActivity;
use Modules\Workflow\Models\WorkflowInstance;

class StockDocument extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['issue' => 'Issue note', 'transfer' => 'Transfer note', 'receipt' => 'Stock receipt', 'count' => 'Stock count'];

    public const PREFIX = ['issue' => 'ISS', 'transfer' => 'TRF', 'receipt' => 'SRV', 'count' => 'CNT'];

    public const REASONS = ['opening' => 'Opening balance', 'donation' => 'Donation', 'found' => 'Found stock', 'return' => 'Returned by a department'];

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'rejected' => 'Returned', 'posted' => 'Posted', 'awaiting' => 'Awaiting receipt',
        'transit' => 'In transit', 'received' => 'Received', 'cancelled' => 'Cancelled', 'reversed' => 'Reversed', 'counting' => 'Counting'];

    protected $fillable = ['company_id', 'number', 'kind', 'reason', 'store_id', 'to_store_id', 'store_requisition_id', 'department_id', 'task_activity_id', 'received_by_user_id', 'date', 'notes',
        'status', 'value', 'journal_entry_id', 'reversal_of_id', 'created_by', 'decision_comment', 'posted_at', 'confirmed_at'];

    protected $casts = ['date' => 'date', 'value' => 'float', 'posted_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function toStore()
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function requisition()
    {
        return $this->belongsTo(StoreRequisition::class, 'store_requisition_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function activity()
    {
        return $this->belongsTo(TaskActivity::class, 'task_activity_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function lines()
    {
        return $this->hasMany(StockDocumentLine::class)->orderBy('id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
