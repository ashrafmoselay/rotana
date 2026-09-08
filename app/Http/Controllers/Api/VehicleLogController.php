<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceCard;
use App\Models\PurchaseOrder;
use App\Models\Vehicle;
use App\Support\Access;
use App\Support\Amounts;
use App\Support\UiText;
use Illuminate\Http\Request;

class VehicleLogController extends Controller
{
    public function summary(Request $request)
    {
        Access::allow('reports.view');

        $data = $request->validate([
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ]);

        if (! empty($data['vehicle_id'])) {
            Access::branch(Vehicle::whereKey($data['vehicle_id'])->value('branch_id'));
        }

        $orders = Access::scope(PurchaseOrder::query())
            ->when($data['vehicle_id'] ?? null, fn ($query, $id) => $query->where('vehicle_id', $id))
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date));

        $cards = Access::scope(MaintenanceCard::query())
            ->when($data['vehicle_id'] ?? null, fn ($query, $id) => $query->where('vehicle_id', $id))
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->whereHas('order', fn ($order) => $order->where('supplier_id', $id)))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date));

        return response()->json([
            'records' => (clone $orders)->count() + (clone $cards)->count(),
            'orders' => (clone $orders)->count(),
            'cards' => (clone $cards)->count(),
            'total_minor' => (int) (clone $orders)->sum('total_minor'),
            'paid_minor' => (int) (clone $orders)->join('payments', 'payments.purchase_order_id', '=', 'purchase_orders.id')->sum('payments.amount_minor'),
        ]);
    }

    public function index(Request $request)
    {
        Access::allow('reports.view');

        $data = $request->validate([
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ]);

        if (! empty($data['vehicle_id'])) {
            Access::branch(Vehicle::whereKey($data['vehicle_id'])->value('branch_id'));
        }

        $orders = Access::scope(PurchaseOrder::query())
            ->with('supplier:id,name', 'vehicle:id,plate', 'payment:id,purchase_order_id,amount_minor', 'lines:id,purchase_order_id,description')
            ->when($data['vehicle_id'] ?? null, fn ($query, $id) => $query->where('vehicle_id', $id))
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'id' => 'order-'.$order->id,
                'record_id' => $order->id,
                'record_type' => 'order',
                'number' => $order->number,
                'date' => $order->date,
                'type' => config('rotana.categories.'.$order->category, $order->category),
                'supplier' => $order->supplier?->name ?? $order->supplier_name,
                'vehicle' => $order->vehicle?->plate ?? $order->vehicle_plate,
                'service' => $order->lines->pluck('description')->filter()->implode('، ') ?: ($order->notes ?: '—'),
                'total' => Amounts::money($order->total_minor),
                'paid' => Amounts::money($order->payment?->amount_minor ?? 0),
                'status' => $order->status->label(),
                'url' => '#order/'.$order->id,
            ]);

        $cards = Access::scope(MaintenanceCard::query())
            ->with('vehicle:id,plate', 'order:id,supplier_id,supplier_name', 'order.supplier:id,name')
            ->when($data['vehicle_id'] ?? null, fn ($query, $id) => $query->where('vehicle_id', $id))
            ->when($data['supplier_id'] ?? null, fn ($query, $id) => $query->whereHas('order', fn ($order) => $order->where('supplier_id', $id)))
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->get()
            ->map(fn (MaintenanceCard $card) => [
                'id' => 'card-'.$card->id,
                'record_id' => $card->id,
                'record_type' => 'card',
                'number' => $card->number,
                'date' => $card->date,
                'type' => 'كارت صيانة',
                'supplier' => $card->order?->supplier?->name ?? $card->order?->supplier_name,
                'vehicle' => $card->vehicle?->plate,
                'service' => $card->type,
                'total' => '—',
                'paid' => '—',
                'status' => UiText::cardStatusLabel($card->status),
                'url' => '#cards?vehicle_id='.$card->vehicle_id,
            ]);

        $rows = $orders->concat($cards)->sortByDesc('date')->values();
        $recordsTotal = $rows->count();
        $search = mb_strtolower(trim((string) $request->input('search.value')));
        if ($search !== '') {
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower(implode(' ', array_map(fn ($value) => (string) $value, $row))), $search))->values();
        }

        $total = $rows->count();
        $start = max((int) $request->input('start', 0), 0);
        $length = min(max((int) $request->input('length', 10), 1), 100);

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $total,
            'data' => $rows->slice($start, $length)->values(),
        ]);
    }
}
