<?php

namespace App\Services;

use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GlobalSearch
{
    private const ENTITY_LIMIT = 5;
    private const TOTAL_LIMIT = 15;

    public function search(string $term): array
    {
        $term = trim($term);
        $results = collect();

        $this->append($results, 'orders.view', fn () => $this->orders($term));
        $this->append($results, 'vehicles.view', fn () => $this->vehicles($term));
        $this->append($results, 'cards.view', fn () => $this->cards($term));
        $this->append($results, 'suppliers.view', fn () => $this->suppliers($term));
        $this->append($results, 'items.view', fn () => $this->items($term));

        return ['results' => $results->take(self::TOTAL_LIMIT)->values()->all()];
    }

    private function append(Collection $results, string $permission, callable $callback): void
    {
        if (! auth()->user()->can($permission) || $results->count() >= self::TOTAL_LIMIT) {
            return;
        }

        $results->push(...$callback());
    }

    private function orders(string $term): array
    {
        return Access::scope(PurchaseOrder::query())
            ->select('id', 'number', 'date', 'status', 'branch_name', 'supplier_name', 'vehicle_plate')
            ->where(fn ($q) => $this->prefix($q, 'number', $term)->orWhere(fn ($q) => $this->contains($q, 'supplier_name', $term)))
            ->latest('id')
            ->limit(self::ENTITY_LIMIT)
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'type' => 'purchase_orders',
                'label' => $order->number,
                'secondary' => trim($order->supplier_name.' · '.$order->branch_name.' · '.$order->date, ' ·'),
                'url' => '#order/'.$order->id,
                'icon' => 'file-invoice',
            ])->all();
    }

    private function vehicles(string $term): array
    {
        return Access::scope(Vehicle::query())
            ->select('id', 'plate', 'model', 'year', 'branch_id')
            ->where(fn ($q) => $this->prefix($q, 'plate', $term)->orWhere(fn ($q) => $this->contains($q, 'model', $term)))
            ->orderBy('plate')
            ->limit(self::ENTITY_LIMIT)
            ->get()
            ->map(fn (Vehicle $vehicle) => [
                'type' => 'vehicles',
                'label' => $vehicle->plate,
                'secondary' => trim($vehicle->model.' · '.$vehicle->year, ' ·'),
                'url' => '#master/vehicles/'.$vehicle->id,
                'icon' => 'car',
            ])->all();
    }

    private function cards(string $term): array
    {
        return Access::scope(MaintenanceCard::query())
            ->select('id', 'number', 'date', 'type', 'status', 'vehicle_id', 'branch_id')
            ->with('vehicle:id,plate')
            ->where(function ($q) use ($term) {
                $this->prefix($q, 'number', $term)
                    ->orWhere(fn ($q) => $this->contains($q, 'type', $term))
                    ->orWhereHas('vehicle', fn ($q) => $this->prefix($q, 'plate', $term));
            })
            ->latest('id')
            ->limit(self::ENTITY_LIMIT)
            ->get()
            ->map(fn (MaintenanceCard $card) => [
                'type' => 'maintenance_cards',
                'label' => $card->number,
                'secondary' => trim(($card->vehicle?->plate ?? '').' · '.$card->type.' · '.$card->date, ' ·'),
                'url' => '#cards',
                'icon' => 'screwdriver-wrench',
            ])->all();
    }

    private function suppliers(string $term): array
    {
        return Supplier::query()
            ->select('id', 'code', 'name', 'phone')
            ->where(fn ($q) => $this->prefix($q, 'code', $term)->orWhere(fn ($q) => $this->prefix($q, 'phone', $term))->orWhere(fn ($q) => $this->contains($q, 'name', $term)))
            ->orderBy('name')
            ->limit(self::ENTITY_LIMIT)
            ->get()
            ->map(fn (Supplier $supplier) => [
                'type' => 'suppliers',
                'label' => $supplier->name,
                'secondary' => trim($supplier->code.' · '.$supplier->phone, ' ·'),
                'url' => '#master/suppliers/'.$supplier->id,
                'icon' => 'truck-field',
            ])->all();
    }

    private function items(string $term): array
    {
        return Item::query()
            ->select('id', 'sku', 'name', 'unit')
            ->where(fn ($q) => $this->prefix($q, 'sku', $term)->orWhere(fn ($q) => $this->contains($q, 'name', $term)))
            ->orderBy('name')
            ->limit(self::ENTITY_LIMIT)
            ->get()
            ->map(fn (Item $item) => [
                'type' => 'items',
                'label' => $item->name,
                'secondary' => trim($item->sku.' · '.$item->unit, ' ·'),
                'url' => '#master/items/'.$item->id,
                'icon' => 'box',
            ])->all();
    }

    private function prefix(Builder $q, string $column, string $term): Builder
    {
        return $this->like($q, $column, $this->escapeLike($term).'%');
    }

    private function contains(Builder $q, string $column, string $term): Builder
    {
        return $this->like($q, $column, '%'.$this->escapeLike($term).'%');
    }

    private function like(Builder $q, string $column, string $pattern): Builder
    {
        // Avoid a backslash escape character: its SQL representation changes
        // across MySQL modes and produced the invalid `escape ''` clause.
        return $q->whereRaw($column." like ? escape '!'", [$pattern]);
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
