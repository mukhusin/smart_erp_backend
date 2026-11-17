<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockDocumentLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_document_id', 'item_id', 'unit_id', 'store_requisition_line_id', 'stock_layer_id', 'quantity', 'base_quantity', 'system_quantity', 'received_quantity',
        'unit_cost', 'value', 'batch_number', 'expiry_date', 'reason'];

    protected $casts = ['quantity' => 'float', 'base_quantity' => 'float', 'system_quantity' => 'float', 'received_quantity' => 'float', 'unit_cost' => 'float', 'value' => 'float', 'expiry_date' => 'date'];

    public function document()
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function layer()
    {
        return $this->belongsTo(StockLayer::class, 'stock_layer_id');
    }
}
