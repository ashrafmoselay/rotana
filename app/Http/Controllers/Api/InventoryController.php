<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Support\Access;
use App\Support\Audit;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;

class InventoryController extends Controller
{
    public function balances(Request $r)
    {
        Access::allow('inventory.view');
        $this->clampDataTableLength($r);

        $w = Warehouse::findOrFail($r->integer('warehouse_id'));
        Access::branch($w->branch_id);
        $q = Item::query()->where('track_stock', true)->with(['balances' => fn ($q) => $q->where('warehouse_id', $w->id)]);
        if ($r->filled('item_id')) {
            $r->validate(['item_id' => 'integer|exists:items,id']);
            $q->whereKey($r->integer('item_id'));
        }
        if ($r->boolean('low_stock')) {
            $q->whereHas('balances', fn ($q) => $q->where('warehouse_id', $w->id)->whereColumn('stock_balances.quantity_milli', '<', 'items.minimum_milli'));
        }

        return DataTables::eloquent($q)
            ->filterColumn('quantity_milli', function ($q, $keyword) use ($w) {
                $q->whereHas('balances', fn ($q) => $q->where('warehouse_id', $w->id)->where('quantity_milli', (int) round(((float) $keyword) * 1000)));
            })
            ->orderColumn('quantity_milli', function ($q, $order) use ($w) {
                $q->orderBy(
                    StockBalance::select('quantity_milli')
                        ->whereColumn('stock_balances.item_id', 'items.id')
                        ->where('warehouse_id', $w->id)
                        ->limit(1),
                    $order
                );
            })
            ->addColumn('quantity_milli', fn ($i) => $i->balances->first()?->quantity_milli ?? 0)
            ->escapeColumns([])
            ->toJson();
    }

    public function movements(Request $r)
    {
        Access::allow('inventory.view');
        $this->clampDataTableLength($r);

        $q = StockMovement::with('lines.item', 'warehouse', 'destinationWarehouse', 'vehicle');
        if ($r->filled('warehouse_id')) {
            Access::branch(Warehouse::findOrFail($r->integer('warehouse_id'))->branch_id);
            $q->where(fn ($q) => $q->where('warehouse_id', $r->integer('warehouse_id'))
                ->orWhere('destination_warehouse_id', $r->integer('warehouse_id')));
        } elseif (! auth()->user()->all_branches) {
            $q->whereHas('warehouse', fn ($q) => Access::scope($q));
        }
        foreach (['vehicle_id', 'type'] as $k) {
            if ($r->filled($k)) {
                $q->where($k, $r->input($k));
            }
        }

        if ($r->filled('item_id')) {
            $r->validate(['item_id' => 'integer|exists:items,id']);
            $q->whereHas('lines', fn ($q) => $q->where('item_id', $r->integer('item_id')))
                ->with(['lines' => fn ($q) => $q->where('item_id', $r->integer('item_id'))->with('item')]);
        }

        $table = DataTables::eloquent($q)->escapeColumns([]);
        if ($r->boolean('export')) {
            Access::allow('excel.export');
            $lines = StockMovementLine::whereIn('stock_movement_id', (clone $q)->select('stock_movements.id'));
            if ($r->filled('item_id')) {
                $lines->where('item_id', $r->integer('item_id'));
            }
            abort_if($lines->count() > 20000, 422, 'ضيّق نطاق التصدير إلى 20000 بند حركة أو أقل.');
            $rows = [];
            $labels = UiText::frontend()['movement_type_labels'];
            foreach ($table->skipPaging()->toArray()['data'] as $movement) {
                foreach ($movement['lines'] as $line) {
                    $quantity = $line['quantity_milli'];
                    if ($r->filled('warehouse_id') && $movement['destination_warehouse_id'] == $r->integer('warehouse_id')) {
                        $quantity = -$quantity;
                    }
                    $rows[] = [$movement['number'], $movement['date'], $labels[$movement['type']] ?? $movement['type'],
                        $movement['warehouse']['name'] ?? '', $movement['destination_warehouse']['name'] ?? '',
                        $movement['vehicle']['plate'] ?? '', $line['item']['sku'] ?? '', $line['item']['name'] ?? '',
                        $line['item']['unit'] ?? '', $quantity / 1000, $movement['notes'] ?? ''];
                }
            }
            Audit::record('excel.exported', null, ['kind' => 'inventory-movements', 'rows' => count($rows), 'warehouse_id' => $r->input('warehouse_id'), 'item_id' => $r->input('item_id')]);

            return Excel::download(new TableExport(['رقم الحركة', 'التاريخ', 'نوع الحركة', 'المخزن', 'المخزن المستلم', 'السيارة', 'كود الصنف', 'الصنف', 'الوحدة', 'الكمية (+ وارد / - صادر)', 'الملاحظات'], $rows), 'inventory-movements-'.today()->format('Y-m-d').'.xlsx');
        }

        return $table->toJson();
    }

    public function items(Request $r)
    {
        Access::allow('inventory.view');
        $w = Warehouse::findOrFail($r->integer('warehouse_id'));
        Access::branch($w->branch_id);
        $movements = StockMovement::where('warehouse_id', $w->id)->orWhere('destination_warehouse_id', $w->id)->select('id');

        return Item::where(fn ($q) => $q->whereHas('balances', fn ($q) => $q->where('warehouse_id', $w->id))
            ->orWhereIn('id', StockMovementLine::whereIn('stock_movement_id', $movements)->select('item_id')))
            ->orderBy('name')->get(['id', 'sku', 'name']);
    }

    public function store(Request $r, InventoryService $service)
    {
        $data = $r->validate(['request_key' => 'required|uuid', 'type' => ['required', Rule::in(['issue', 'transfer', 'return', 'adjust'])], 'warehouse_id' => 'required|integer|exists:warehouses,id', 'destination_warehouse_id' => 'nullable|integer|exists:warehouses,id', 'vehicle_id' => 'nullable|integer|exists:vehicles,id', 'source_movement_id' => 'nullable|integer|exists:stock_movements,id', 'odometer' => 'nullable|integer|min:0|max:999999999', 'date' => 'required|date_format:Y-m-d', 'notes' => 'required_if:type,adjust|nullable|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'required|integer|distinct|exists:items,id', 'lines.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(\.\d{1,3})?$/']]);

        return response()->json($service->move($data), 201);
    }

    private function clampDataTableLength(Request $r): void
    {
        if ($r->has('length')) {
            $r->merge(['length' => min(max((int) $r->input('length'), 1), 100)]);
        }
    }
}
