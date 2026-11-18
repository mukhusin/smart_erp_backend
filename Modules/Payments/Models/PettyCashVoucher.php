<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Workflow\Models\WorkflowInstance;

class PettyCashVoucher extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Paid out', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];

    protected $fillable = ['company_id', 'number', 'petty_cash_float_id', 'date', 'payee', 'description', 'budget_item_id', 'amount', 'input_vat', 'vat_invoice_no', 'receipt_path', 'receipt_name', 'status',
        'over_budget', 'journal_entry_id', 'petty_cash_replenishment_id', 'created_by', 'decision_comment', 'decided_at'];

    protected $casts = ['date' => 'date', 'amount' => 'float', 'input_vat' => 'float', 'over_budget' => 'boolean', 'decided_at' => 'datetime'];

    public function float()
    {
        return $this->belongsTo(PettyCashFloat::class, 'petty_cash_float_id');
    }

    public function item()
    {
        return $this->belongsTo(BudgetItem::class, 'budget_item_id');
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
