<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'quantity_milli' => 'integer', 'unit_cost_minor' => 'integer', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'amount_minor' => 'integer', 'minimum_milli' => 'integer', 'odometer' => 'integer'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function costCenter()
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function orders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
