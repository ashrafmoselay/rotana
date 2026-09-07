<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PurchaseOrder extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'tax_basis_points' => 'integer'];
    }

    public function lines()
    {
        return $this->hasMany(OrderLine::class);
    }

    public function approvals()
    {
        return $this->hasMany(ApprovalEvent::class);
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }

    public function invoice()
    {
        return $this->hasOne(SupplierInvoice::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registerMediaCollections(): void
    {
        foreach (['quote', 'photos_before', 'photos_after', 'attachments', 'invoice', 'proof'] as $name) {
            $this->addMediaCollection($name)->useDisk('private_media');
        }
    }
}
