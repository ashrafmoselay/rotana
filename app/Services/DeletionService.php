<?php

namespace App\Services;

use App\Models\{Branch, CostCenter, Item, MaintenanceCard, PurchaseOrder, Region, StockBalance, StockMovement, Supplier, Vehicle, Warehouse};
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Central, transactional deletion policy. Records with accounting or operational dependencies are never force-deleted. */
class DeletionService
{
    private const MODELS = [
        'regions' => Region::class, 'branches' => Branch::class, 'cost-centers' => CostCenter::class,
        'warehouses' => Warehouse::class, 'suppliers' => Supplier::class, 'vehicles' => Vehicle::class,
        'items' => Item::class, 'orders' => PurchaseOrder::class, 'movements' => StockMovement::class,
        'stock-balances' => StockBalance::class,
    ];

    public function destroy(string $kind, array $ids, ?int $warehouseId = null): array
    {
        $model = self::MODELS[$kind] ?? abort(404);
        Access::allow(match ($kind) {
            'orders' => 'orders.delete', 'movements', 'stock-balances' => 'inventory.delete', default => 'masters.delete',
        });
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || count($ids) > 100) {
            throw ValidationException::withMessages(['ids' => 'اختر من سجل واحد إلى 100 سجل للحذف.']);
        }

        return DB::transaction(function () use ($kind, $model, $ids, $warehouseId) {
            $query = $model::query()->lockForUpdate();
            if ($kind === 'stock-balances') {
                $warehouse = Warehouse::lockForUpdate()->findOrFail($warehouseId);
                Access::branch($warehouse->branch_id);
                $query->where('warehouse_id', $warehouse->id)->whereIn('item_id', $ids);
            } else {
                $query->whereKey($ids);
            }
            $records = $query->get();
            if ($records->count() !== count($ids)) abort(404, 'تعذر العثور على بعض السجلات أو لا تملك صلاحية الوصول إليها.');

            foreach ($records as $record) {
                $this->authorizeScope($kind, $record);
                $this->assertDeletable($kind, $record);
            }
            foreach ($records as $record) {
                if ($kind === 'movements') $this->reverseMovement($record);
                $snapshot = $record->only(['id', 'number', 'name', 'code', 'plate', 'status']);
                $record->delete();
                Audit::record('records.'.$kind.'.deleted', $record, ['branch_id' => $record->branch_id ?? null, 'record' => $snapshot]);
            }
            return ['deleted' => $records->count()];
        }, 3);
    }

    private function authorizeScope(string $kind, Model $record): void
    {
        if (in_array($kind, ['vehicles', 'warehouses', 'movements'])) Access::branch($record->branch_id ?? $record->warehouse->branch_id);
        if ($kind === 'branches') Access::branch($record->id);
        if ($kind === 'orders') Access::branch($record->branch_id);
    }

    private function assertDeletable(string $kind, Model $record): void
    {
        $exists = fn ($query) => $query->exists();
        $blocked = match ($kind) {
            'regions' => $exists($record->branches()),
            'branches' => $exists(Warehouse::where('branch_id', $record->id)) || $exists(Vehicle::where('branch_id', $record->id)) || $exists(PurchaseOrder::where('branch_id', $record->id)) || $exists(MaintenanceCard::where('branch_id', $record->id)) || $record->users()->exists(),
            'cost-centers' => $exists(Vehicle::where('cost_center_id', $record->id)) || $exists(PurchaseOrder::where('cost_center_id', $record->id)),
            'warehouses' => $exists(StockBalance::where('warehouse_id', $record->id)) || $exists(StockMovement::where('warehouse_id', $record->id)->orWhere('destination_warehouse_id', $record->id)) || $exists(PurchaseOrder::where('warehouse_id', $record->id)),
            'suppliers' => $exists(PurchaseOrder::where('supplier_id', $record->id)) || $exists(\App\Models\SupplierInvoice::where('supplier_id', $record->id)),
            'vehicles' => $exists(PurchaseOrder::where('vehicle_id', $record->id)) || $exists(MaintenanceCard::where('vehicle_id', $record->id)) || $exists(StockMovement::where('vehicle_id', $record->id)),
            'items' => $exists(StockBalance::where('item_id', $record->id)) || $exists(\App\Models\OrderLine::where('item_id', $record->id)) || $exists(\App\Models\StockMovementLine::where('item_id', $record->id)),
            'orders' => $record->status->value !== 'draft' || $record->receipt()->exists() || $record->invoice()->exists() || $record->payment()->exists() || $exists(StockMovement::where('purchase_order_id', $record->id)),
            'movements' => !in_array($record->type, ['issue', 'transfer', 'return', 'adjust'], true) || $exists(StockMovement::where('source_movement_id', $record->id)) || $exists(StockMovement::where('purchase_order_id', $record->purchase_order_id)->whereNotNull('purchase_order_id')),
            'stock-balances' => $record->quantity_milli !== 0,
            default => true,
        };
        if ($blocked) throw ValidationException::withMessages(['delete' => 'لا يمكن حذف هذا السجل لارتباطه ببيانات تشغيلية أو مالية. استخدم التعطيل أو إجراء تصحيحي بدلًا من ذلك.']);
    }

    private function reverseMovement(StockMovement $movement): void
    {
        $movement->loadMissing('lines', 'warehouse');
        $deltas = [];
        foreach ($movement->lines as $line) {
            $deltas[$movement->warehouse_id.':'.$line->item_id] = ($deltas[$movement->warehouse_id.':'.$line->item_id] ?? 0) - $line->quantity_milli;
            if ($movement->destination_warehouse_id) $deltas[$movement->destination_warehouse_id.':'.$line->item_id] = ($deltas[$movement->destination_warehouse_id.':'.$line->item_id] ?? 0) - abs($line->quantity_milli);
        }
        app(InventoryService::class)->apply($deltas);
    }
}
