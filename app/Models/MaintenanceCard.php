<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceCard extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'quantity_milli' => 'integer', 'unit_cost_minor' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'amount_minor' => 'integer', 'minimum_milli' => 'integer', 'odometer' => 'integer'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function order()
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
