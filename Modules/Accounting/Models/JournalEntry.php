<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

class JournalEntry extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'posted' => 'Posted', 'rejected' => 'Returned', 'reversed' => 'Reversed'];

    public const TYPES = ['manual' => 'Manual journal', 'payment' => 'Payment voucher', 'payment_list' => 'Payment list', 'invoice' => 'Customer invoice', 'receipt' => 'Receipt', 'credit_note' => 'Credit note', 'goods_receipt' => 'Goods received', 'stock_issue' => 'Stock issued', 'stock_receipt' => 'Stock received', 'stock_adjustment' => 'Stock count adjustment', 'payroll' => 'Payroll', 'reversal' => 'Reversal', 'opening' => 'Opening balance'];

    protected $fillable = [
        'company_id', 'reference_no', 'date', 'fiscal_period_id', 'entry_type', 'source_type', 'source_id', 'description', 'total', 'status',
        'created_by', 'posted_at', 'posted_by', 'reversal_of_id', 'decision_comment', 'book',
    ];

    protected $casts = ['date' => 'date', 'posted_at' => 'datetime', 'total' => 'float'];

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_no');
    }

    public function period()
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function reversedBy()
    {
        return $this->hasOne(JournalEntry::class, 'reversal_of_id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
