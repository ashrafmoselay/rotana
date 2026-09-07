<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'quantity_milli' => 'integer', 'unit_cost_minor' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'amount_minor' => 'integer', 'minimum_milli' => 'integer', 'odometer' => 'integer'];
    }

    public function lines()
    {
        return $this->hasMany(StockMovementLine::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
