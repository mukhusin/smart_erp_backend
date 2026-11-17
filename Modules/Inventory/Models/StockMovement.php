<?php

namespace Modules\Inventory\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const TYPES = ['receipt' => 'Received', 'opening' => 'Opening balance', 'issue' => 'Issued', 'transfer_out' => 'Transferred out', 'transfer_in' => 'Transferred in',
        'adjustment_in' => 'Count gain', 'adjustment_out' => 'Count loss', 'reversal' => 'Reversal', 'donation' => 'Donation received', 'return' => 'Returned'];

    protected $fillable = ['company_id', 'store_id', 'item_id', 'stock_layer_id', 'date', 'type', 'quantity', 'unit_cost', 'value', 'balance_quantity', 'balance_value', 'reference',
        'source_type', 'source_id', 'department_id', 'user_id'];

    protected $casts = ['date' => 'date', 'quantity' => 'float', 'unit_cost' => 'float', 'value' => 'float', 'balance_quantity' => 'float', 'balance_value' => 'float'];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function layer()
    {
        return $this->belongsTo(StockLayer::class, 'stock_layer_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
