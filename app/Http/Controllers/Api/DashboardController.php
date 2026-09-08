<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceCard;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Support\Access;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function __invoke()
    {
        Access::allow('dashboard.view');
        $canOrders = auth()->user()->can('orders.view');
        $canInventory = auth()->user()->can('inventory.view');
        $canCards = auth()->user()->can('cards.view');
        $canVehicles = auth()->user()->can('vehicles.view');
        $q = Access::scope(PurchaseOrder::query());
        $visibleOrders = $canOrders ? $q : PurchaseOrder::query()->whereRaw('1 = 0');
        $counts = (clone $visibleOrders)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $categoryCounts = (clone $visibleOrders)->selectRaw('category, count(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category');
        $totals = (clone $visibleOrders)->selectRaw('category, SUM(total_minor) as total_minor')->groupBy('category')->get();
        $low = StockBalance::join('items', 'items.id', '=', 'stock_balances.item_id')->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')->whereColumn('stock_balances.quantity_milli', '<', 'items.minimum_milli');
        if (! $canInventory) {
            $low->whereRaw('1 = 0');
        } elseif (! auth()->user()->all_branches) {
            $low->whereIn('warehouses.branch_id', auth()->user()->branches()->select('branches.id'));
        }

        $operations = Activity::with('causer:id,name', 'subject')->latest('id')->limit(40)->get()
            ->filter(fn (Activity $item) => $this->visibleActivity($item, $canOrders, $canInventory, $canCards, $canVehicles))
            ->take(8)->map(fn (Activity $item) => [
                'id' => $item->id,
                'event' => UiText::activityEventLabel($item->event),
                'subject' => UiText::subjectLabel($item->subject_type),
                'reference' => UiText::subjectReference($item),
                'user' => $item->causer?->name ?? 'النظام',
                'created_at' => $item->created_at,
            ])->values();

        return response()->json([
            'counts' => $counts,
            'category_counts' => $categoryCounts,
            'totals' => $totals,
            'order_total_minor' => (clone $visibleOrders)->sum('total_minor'),
            'paid_minor' => $canOrders ? Payment::whereHas('order', fn ($q) => Access::scope($q))->sum('amount_minor') : 0,
            'low_stock' => $low->count(),
            'vehicles' => $canVehicles ? Access::scope(Vehicle::where('active', true))->count() : 0,
            'cards' => $canCards ? Access::scope(MaintenanceCard::query())->where('status', '!=', 'closed')->count() : 0,
            'recent' => (clone $visibleOrders)->latest('id')->limit(8)->get(),
            'recent_operations' => $operations,
            'attention' => $this->attention($visibleOrders, $canCards),
            'summaries' => $this->summaries($visibleOrders, $canInventory, $canCards),
        ]);
    }

    public function chart(Request $request)
    {
        Access::allow('dashboard.view');
        $period = $request->string('period', 'monthly')->toString();
        $periods = [
            'daily' => ['start' => now()->subDays(6)->startOfDay(), 'end' => now()->endOfDay(), 'step' => 'day', 'format' => 'd/m'],
            'weekly' => ['start' => now()->subWeeks(7)->startOfWeek(), 'end' => now()->endOfWeek(), 'step' => 'week', 'format' => 'd/m'],
            'monthly' => ['start' => now()->subMonths(11)->startOfMonth(), 'end' => now()->endOfMonth(), 'step' => 'month', 'format' => 'm/y'],
            'yearly' => ['start' => now()->subYears(4)->startOfYear(), 'end' => now()->endOfYear(), 'step' => 'year', 'format' => 'Y'],
        ];
        $config = $periods[$period] ?? $periods['monthly'];
        $start = $config['start']; $end = $config['end'];
        $canOrders = auth()->user()->can('orders.view');
        $orders = $canOrders ? Access::scope(PurchaseOrder::query())->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get(['date', 'total_minor']) : collect();
        $labels = $ordersSeries = $spendSeries = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $bucketStart = $cursor->copy();
            $bucketEnd = match ($config['step']) { 'day' => $cursor->copy()->endOfDay(), 'week' => $cursor->copy()->endOfWeek(), 'month' => $cursor->copy()->endOfMonth(), default => $cursor->copy()->endOfYear() };
            $ordersInBucket = $orders->filter(fn ($order) => Carbon::parse($order->date)->betweenIncluded($bucketStart, $bucketEnd));
            $labels[] = $bucketStart->translatedFormat($config['format']);
            $ordersSeries[] = $ordersInBucket->count(); $spendSeries[] = round($ordersInBucket->sum('total_minor') / 100, 2);
            $cursor = match ($config['step']) { 'day' => $cursor->addDay(), 'week' => $cursor->addWeek()->startOfWeek(), 'month' => $cursor->addMonth()->startOfMonth(), default => $cursor->addYear()->startOfYear() };
        }
        return response()->json(['period' => array_key_exists($period, $periods) ? $period : 'monthly', 'labels' => $labels, 'orders' => $ordersSeries, 'spend' => $spendSeries]);
    }

    public function recent(string $type)
    {
        Access::allow('dashboard.view');

        return response()->json(match ($type) {
            'orders' => $this->recentOrders(),
            'cards' => $this->recentCards(),
            'movements' => $this->recentMovements(),
            'payments' => $this->recentPayments(),
            default => abort(404),
        });
    }

    private function attention($orders, bool $canCards): array
    {
        $items = [];
        if (auth()->user()->can('orders.review')) {
            $items[] = ['label' => 'مراجعة طلبات', 'value' => (int) (clone $orders)->where('status', 'accountant')->count(), 'icon' => 'file-invoice', 'color' => 'blue', 'url' => '#orders?status=accountant'];
        }
        if (auth()->user()->can('orders.match')) {
            $items[] = ['label' => 'مطابقة قبل التحويل', 'value' => (int) (clone $orders)->where('status', 'matching')->count(), 'icon' => 'clipboard-check', 'color' => 'teal', 'url' => '#orders?status=matching'];
        }
        if (auth()->user()->can('payments.create')) {
            $items[] = ['label' => 'جاهز للتحويل', 'value' => (int) (clone $orders)->where('status', 'ready')->count(), 'icon' => 'building-columns', 'color' => 'green', 'url' => '#orders?status=ready'];
        }
        if ($canCards && auth()->user()->can('cards.manage')) {
            $items[] = ['label' => 'كروت صيانة مكتملة', 'value' => (int) Access::scope(MaintenanceCard::query())->where('status', 'completed')->count(), 'icon' => 'screwdriver-wrench', 'color' => 'orange', 'url' => '#cards?status=completed'];
        }

        return $items;
    }

    private function summaries($orders, bool $canInventory, bool $canCards): array
    {
        $summaries = [];
        if (auth()->user()->can('orders.view')) {
            $summaries[] = [
                'key' => 'orders', 'title' => 'الطلبات والمشتريات', 'icon' => 'file-invoice', 'url' => '#orders',
                'items' => [
                    ['label' => 'مفتوح', 'value' => (int) (clone $orders)->whereIn('status', ['draft', 'accountant', 'manager', 'supervisor', 'matching', 'ready'])->count(), 'url' => '#orders?open=1'],
                    ['label' => 'جاهز للتحويل', 'value' => (int) (clone $orders)->where('status', 'ready')->count(), 'url' => '#orders?status=ready'],
                    ['label' => 'مغلق', 'value' => (int) (clone $orders)->where('status', 'closed')->count(), 'url' => '#orders?status=closed'],
                ],
            ];
        }
        if ($canCards) {
            $cards = Access::scope(MaintenanceCard::query());
            $summaries[] = [
                'key' => 'cards', 'title' => 'الصيانة', 'icon' => 'screwdriver-wrench', 'url' => '#cards',
                'items' => [
                    ['label' => 'كروت مفتوحة', 'value' => (int) (clone $cards)->where('status', 'pending')->count(), 'url' => '#cards?status=pending'],
                    ['label' => 'انتظار قطع', 'value' => (int) (clone $cards)->where('status', 'waiting_parts')->count(), 'url' => '#cards?status=waiting_parts'],
                    ['label' => 'تحت الصيانة', 'value' => (int) (clone $cards)->where('status', 'in_progress')->count(), 'url' => '#cards?status=in_progress'],
                ],
            ];
        }
        if ($canInventory) {
            $movements = StockMovement::query();
            if (! auth()->user()->all_branches) {
                $movements->whereHas('warehouse', fn ($query) => Access::scope($query));
            }
            $low = StockBalance::join('items', 'items.id', '=', 'stock_balances.item_id')->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')->whereColumn('stock_balances.quantity_milli', '<', 'items.minimum_milli');
            if (! auth()->user()->all_branches) {
                $low->whereIn('warehouses.branch_id', auth()->user()->branches()->select('branches.id'));
            }
            $summaries[] = [
                'key' => 'inventory', 'title' => 'المخزون', 'icon' => 'boxes-stacked', 'url' => '#inventory',
                'items' => [
                    ['label' => 'اقتراحات توريد', 'value' => (int) $low->count(), 'url' => '#inventory?low_stock=1'],
                    ['label' => 'تحويلات', 'value' => (int) (clone $movements)->where('type', 'transfer')->count(), 'url' => '#inventory?movements=1&type=transfer'],
                    ['label' => 'أصناف تحت الحد', 'value' => (int) (clone $low)->count(), 'url' => '#inventory?low_stock=1'],
                ],
            ];
        }

        return $summaries;
    }

    private function recentOrders(): array
    {
        Access::allow('orders.view');

        return Access::scope(PurchaseOrder::query())->latest('id')->limit(8)->get()->map(fn (PurchaseOrder $order) => [
            'number' => $order->number, 'category' => config('rotana.categories.'.$order->category, $order->category), 'branch' => $order->branch_name, 'supplier' => $order->supplier_name, 'total_minor' => $order->total_minor, 'status' => UiText::statusLabel($order->status->value), 'url' => '#order/'.$order->id,
        ])->all();
    }

    private function recentCards(): array
    {
        Access::allow('cards.view');

        return Access::scope(MaintenanceCard::with('vehicle:id,plate'))->latest('id')->limit(8)->get()->map(fn (MaintenanceCard $card) => [
            'number' => $card->number, 'vehicle' => $card->vehicle?->plate ?? '—', 'type' => $card->type, 'status' => UiText::cardStatusLabel($card->status), 'date' => $card->date, 'url' => '#cards?status='.$card->status,
        ])->all();
    }

    private function recentMovements(): array
    {
        Access::allow('inventory.view');
        $movements = StockMovement::with('warehouse:id,name', 'vehicle:id,plate')->latest('id')->limit(8);
        if (! auth()->user()->all_branches) {
            $movements->whereHas('warehouse', fn ($query) => Access::scope($query));
        }

        return $movements->get()->map(fn (StockMovement $movement) => [
            'number' => $movement->number, 'type' => UiText::movementTypeLabel($movement->type), 'warehouse' => $movement->warehouse?->name ?? '—', 'vehicle' => $movement->vehicle?->plate ?? '—', 'date' => $movement->date, 'url' => '#inventory',
        ])->all();
    }

    private function recentPayments(): array
    {
        Access::allow('orders.view');

        return Payment::with('order:id,number,branch_id')->whereHas('order', fn ($query) => Access::scope($query))->latest('id')->limit(8)->get()->map(fn (Payment $payment) => [
            'reference' => $payment->reference, 'order' => $payment->order?->number ?? '—', 'amount_minor' => $payment->amount_minor, 'date' => $payment->date, 'url' => $payment->order ? '#order/'.$payment->order->id : '#orders',
        ])->all();
    }

    private function visibleActivity(Activity $activity, bool $canOrders, bool $canInventory, bool $canCards, bool $canVehicles): bool
    {
        $properties = $activity->properties?->toArray() ?? [];
        if (! auth()->user()->all_branches && (! isset($properties['branch_id']) || ! auth()->user()->mayAccessBranch((int) $properties['branch_id']))) {
            return false;
        }

        return match (true) {
            str_starts_with($activity->event, 'orders.'), str_starts_with($activity->event, 'receipts.'), str_starts_with($activity->event, 'invoices.'), str_starts_with($activity->event, 'payments.'), str_starts_with($activity->event, 'media.') => $canOrders,
            str_starts_with($activity->event, 'inventory.') => $canInventory,
            str_starts_with($activity->event, 'cards.') => $canCards,
            str_starts_with($activity->event, 'masters.vehicles.') => $canVehicles,
            str_starts_with($activity->event, 'auth.') => false,
            default => auth()->user()->can('activity.view'),
        };
    }
}
