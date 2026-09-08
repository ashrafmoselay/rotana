<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $q = StockMovement::with('lines.item', 'warehouse', 'vehicle');
        if (! auth()->user()->all_branches) {
            $q->whereHas('warehouse', fn ($q) => Access::scope($q));
        }
        if ($r->filled('warehouse_id')) {
            Access::branch(Warehouse::findOrFail($r->integer('warehouse_id'))->branch_id);
        }
        foreach (['vehicle_id', 'warehouse_id', 'type'] as $k) {
            if ($r->filled($k)) {
                $q->where($k, $r->input($k));
            }
        }

        return DataTables::eloquent($q)->escapeColumns([])->toJson();
    }

    public function store(Request $r, InventoryService $service)
    {
        $data = $r->validate(['request_key' => 'required|uuid', 'type' => ['required', Rule::in(['issue', 'transfer', 'return', 'adjust'])], 'warehouse_id' => 'required|integer|exists:warehouses,id', 'destination_warehouse_id' => 'nullable|integer|exists:warehouses,id', 'vehicle_id' => 'nullable|integer|exists:vehicles,id', 'source_movement_id' => 'nullable|integer|exists:stock_movements,id', 'odometer' => 'nullable|integer|min:0|max:999999999', 'date' => 'required|date_format:Y-m-d', 'notes' => 'required_if:type,adjust|nullable|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'required|integer|distinct|exists:items,id', 'lines.*.quantity' => 'required|integer|min:0|max:1000000']);

        return response()->json($service->move($data), 201);
    }

    private function clampDataTableLength(Request $r): void
    {
        if ($r->has('length')) {
            $r->merge(['length' => min(max((int) $r->input('length'), 1), 100)]);
        }
    }
}
