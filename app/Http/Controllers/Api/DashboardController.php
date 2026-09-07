<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceCard;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\Vehicle;
use App\Support\Access;

class DashboardController extends Controller
{
    public function __invoke()
    {
        Access::allow('dashboard.view');
        $q = Access::scope(PurchaseOrder::query());
        $counts = (clone $q)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $totals = (clone $q)->selectRaw('category, SUM(total_minor) as total_minor')->groupBy('category')->get();
        $low = StockBalance::join('items', 'items.id', '=', 'stock_balances.item_id')->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')->whereColumn('stock_balances.quantity_milli', '<', 'items.minimum_milli');
        if (! auth()->user()->all_branches) {
            $low->whereIn('warehouses.branch_id', auth()->user()->branches()->select('branches.id'));
        }

return response()->json(['counts' => $counts, 'totals' => $totals, 'order_total_minor' => (clone $q)->sum('total_minor'), 'paid_minor' => Payment::whereHas('order', fn ($q) => Access::scope($q))->sum('amount_minor'), 'low_stock' => $low->count(), 'vehicles' => Access::scope(Vehicle::query())->count(), 'cards' => Access::scope(MaintenanceCard::query())->where('status', '!=', 'closed')->count(), 'recent' => (clone $q)->latest('id')->limit(8)->get()]);
    }
}
