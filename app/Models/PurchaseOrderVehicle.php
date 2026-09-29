<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderVehicle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['odometer' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenanceCard()
    {
        return $this->belongsTo(MaintenanceCard::class);
    }
}
