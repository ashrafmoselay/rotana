<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Support\Access;
use App\Support\Amounts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['inventory' => $message]);
        }
    }

    /** Must be called inside an existing transaction. Keys sorted to avoid competing lock orders. */
    public function apply(array $deltas): void
    {
        ksort($deltas, SORT_NATURAL);
        foreach ($deltas as $key => $delta) {
            [$warehouse,$item] = explode(':', $key);
            DB::table('stock_balances')->insertOrIgnore(['warehouse_id' => $warehouse, 'item_id' => $item, 'quantity_milli' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $locked = [];
        foreach ($deltas as $key => $delta) {
            [$warehouse,$item] = explode(':', $key);
            $balance = StockBalance::where('warehouse_id', $warehouse)->where('item_id', $item)->lockForUpdate()->firstOrFail();
            $this->assert($balance->quantity_milli + $delta >= 0, 'الكمية المطلوبة تتجاوز الرصيد المتاح.');
            $locked[$key] = $balance;
        }
        foreach ($locked as $key => $balance) {
            $balance->quantity_milli += $deltas[$key];
            $balance->save();
        }
    }

    public function move(array $data): StockMovement
    {
        $permission = match ($data['type']) {
            'issue' => 'inventory.issue','transfer' => 'inventory.transfer','return' => 'inventory.return','adjust' => 'inventory.adjust',default => abort(422)
        };
        Access::allow($permission);

        return DB::transaction(function () use ($data) {
            $this->assert(! StockMovement::where('request_key', $data['request_key'])->exists(), 'تم تنفيذ هذه العملية بالفعل؛ حدّث الصفحة.');
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);
            Access::branch($warehouse->branch_id);
            $this->assert($warehouse->active, 'المخزن غير نشط.');
            $destination = null;
            if ($data['type'] === 'transfer') {
                $destination = Warehouse::findOrFail($data['destination_warehouse_id'] ?? 0);
                Access::branch($destination->branch_id);
                $this->assert($destination->active && $destination->id !== $warehouse->id, 'اختر مخزنًا مختلفًا ونشطًا.');
            }
            $vehicle = null;
            if ($data['type'] === 'issue') {
                $vehicle = Vehicle::whereKey($data['vehicle_id'] ?? 0)->lockForUpdate()->firstOrFail();
                Access::branch($vehicle->branch_id);
                $this->assert($vehicle->active, 'السيارة غير نشطة.');
                if (isset($data['odometer'])) {
                    $this->assert($data['odometer'] >= $vehicle->odometer, 'العداد الحالي أقل من آخر قراءة.');
                }
            }
            $source = null;
            if ($data['type'] === 'return') {
                $source = StockMovement::whereKey($data['source_movement_id'] ?? 0)->lockForUpdate()->firstOrFail();
                $this->assert($source->type === 'issue' && $source->warehouse_id === $warehouse->id, 'اختر إذن الصرف الأصلي من نفس المخزن.');
                $vehicle = $source->vehicle;
            }
            $deltas = [];
            $lines = [];
            usort($data['lines'], fn ($a, $b) => $a['item_id'] <=> $b['item_id']);
            foreach ($data['lines'] as $line) {
                $item = Item::findOrFail($line['item_id']);
                $this->assert($item->track_stock && $item->active, 'الصنف ليس صنف مخزون نشطًا.');
                $q = Amounts::scaled($line['quantity'], 3);
                $this->assert($q % 1000 === 0 && ($data['type'] === 'adjust' || $q > 0), 'كمية المخزون يجب أن تكون عددًا صحيحًا.');
                $signed = $q;
                if ($data['type'] === 'return') {
                    $original = $source->lines()->where('item_id', $item->id)->first();
                    $returned = DB::table('stock_movement_lines')->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')->where('stock_movements.type', 'return')->where('source_movement_id', $source->id)->where('item_id', $item->id)->sum('quantity_milli');
                    $this->assert($original && $q <= abs($original->quantity_milli) - $returned, 'كمية المرتجع تتجاوز الكمية المصروفة غير المرتجعة.');
                }
                if (in_array($data['type'], ['issue', 'transfer'])) {
                    $signed = -$q;
                }
                if ($data['type'] === 'adjust') {// Lock before deriving the delta from the physical count.
                    $this->apply([$warehouse->id.':'.$item->id => 0]);
                    $old = StockBalance::where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->firstOrFail()->quantity_milli;
                    $signed = $q - $old;
                }
                $deltas[$warehouse->id.':'.$item->id] = $signed;
                if ($destination) {
                    $deltas[$destination->id.':'.$item->id] = $q;
                }
                $lines[] = ['item_id' => $item->id, 'quantity_milli' => $signed, 'unit_cost_minor' => $source ? ($source->lines()->where('item_id', $item->id)->first()->unit_cost_minor) : $item->unit_cost_minor];
            }
            $this->apply($deltas);
            $movement = StockMovement::create(['request_key' => $data['request_key'], 'type' => $data['type'], 'warehouse_id' => $warehouse->id, 'destination_warehouse_id' => $destination?->id, 'source_movement_id' => $source?->id, 'vehicle_id' => $vehicle?->id, 'odometer' => $data['odometer'] ?? null, 'date' => $data['date'], 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id()]);
            $movement->update(['number' => 'ST-'.now()->year.'-'.str_pad($movement->id, 6, '0', STR_PAD_LEFT)]);
            $movement->lines()->createMany($lines);
            if ($vehicle && $data['type'] === 'issue' && isset($data['odometer'])) {
                $vehicle->update(['odometer' => $data['odometer']]);
            }
            Audit::record('inventory.'.$data['type'], $movement, ['branch_id' => $warehouse->branch_id, 'lines' => $lines]);

            return $movement->load('lines.item', 'vehicle', 'warehouse');
        }, 3);
    }

    public function receipt(int $warehouseId, array $lines, int $orderId, bool $correction): void
    {
        $deltas = [];
        foreach ($lines as $line) {
            $deltas[$warehouseId.':'.$line['item_id']] = $line['quantity_milli'];
        }
        $this->apply($deltas);
        if (! array_filter($deltas)) {
            return;
        }
        $m = StockMovement::create(['type' => $correction ? 'receipt_correction' : 'receipt', 'request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouseId, 'purchase_order_id' => $orderId, 'created_by' => auth()->id(), 'date' => today()]);
        $m->update(['number' => 'ST-'.now()->year.'-'.str_pad($m->id, 6, '0', STR_PAD_LEFT)]);
        $m->lines()->createMany($lines);
    }
}
