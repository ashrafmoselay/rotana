<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovementLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'quantity_milli' => 'integer', 'unit_cost_minor' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'amount_minor' => 'integer', 'minimum_milli' => 'integer', 'odometer' => 'integer'];
    }

    public $timestamps = false;

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
