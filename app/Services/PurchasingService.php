<?php

namespace App\Services;

use App\Enums\OrderStatus as S;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Support\Access;
use App\Support\Amounts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasingService
{
    public function __construct(private InventoryService $inventory) {}

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['order' => $message]);
        }
    }

    public function save(array $data, ?PurchaseOrder $order = null): PurchaseOrder
    {
        Access::allow($order ? 'orders.update' : 'orders.create');
        Access::branch((int) $data['branch_id']);

        return DB::transaction(function () use ($data, $order) {
            if ($order) {
                $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                Access::branch($order->branch_id);
                $this->require($order->status === S::Draft && ! $order->receipt, 'يمكن تعديل المسودة فقط قبل الاستلام.');
            }
            $branch = Branch::with('region')->findOrFail($data['branch_id']);
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $center = CostCenter::findOrFail($data['cost_center_id']);
            $this->require($branch->active && $supplier->active && $center->active, 'الفرع أو المورد أو مركز التكلفة غير نشط.');
            $vehicle = null;
            if (in_array($data['category'], ['maintenance', 'damage', 'parts', 'quotes'])) {
                $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id'] ?? 0);
                Access::branch($vehicle->branch_id);
                $this->require($vehicle->active && $vehicle->branch_id === $branch->id, 'اختر سيارة نشطة من فرع الطلب.');
            }
            $warehouse = null;
            if ($data['category'] === 'stock') {
                $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? 0);
                Access::branch($warehouse->branch_id);
                $this->require($warehouse->active && $warehouse->branch_id === $branch->id, 'اختر مخزنًا نشطًا من فرع الطلب.');
            }
            if (! empty($data['maintenance_card_id'])) {
                $card = MaintenanceCard::lockForUpdate()->findOrFail($data['maintenance_card_id']);
                Access::branch($card->branch_id);
                $this->require($card->vehicle_id === $vehicle?->id && $card->branch_id === $branch->id, 'كارت الصيانة لا يخص هذه السيارة أو الفرع.');
                $this->require(! PurchaseOrder::where('maintenance_card_id', $card->id)->when($order, fn ($q) => $q->whereKeyNot($order->id))->exists(), 'الكارت مرتبط بطلب آخر.');
            }
            $lines = [];
            $subtotal = 0;
            foreach ($data['lines'] as $line) {
                $item = Item::findOrFail($line['item_id']);
                $q = Amounts::scaled($line['quantity'], 3);
                $p = Amounts::scaled($line['unit_price']);
                $this->require($item->active && $q > 0, 'الصنف غير نشط أو الكمية غير صحيحة.');
                if ($warehouse) {
                    $this->require($item->track_stock && $q % 1000 === 0, 'طلبات التزويد تتطلب أصناف مخزون بكميات صحيحة.');
                }
                $total = Amounts::line($q, $p);
                $this->require($total <= 100000000000000, 'قيمة البند تتجاوز الحد المسموح.');
                $lines[] = ['item_id' => $item->id, 'description' => $item->name, 'sku' => $item->sku, 'quantity_milli' => $q, 'unit_price_minor' => $p, 'total_minor' => $total];
                $subtotal += $total;
            }
            $taxBasis = Amounts::scaled($data['tax_percent']);
            $tax = Amounts::tax($subtotal, $taxBasis);
            $this->require($subtotal + $tax <= 99999999900, 'إجمالي الطلب يتجاوز الحد المسموح.');
            $values = ['category' => $data['category'], 'branch_id' => $branch->id, 'cost_center_id' => $center->id, 'supplier_id' => $supplier->id, 'vehicle_id' => $vehicle?->id, 'warehouse_id' => $warehouse?->id, 'maintenance_card_id' => $data['maintenance_card_id'] ?? null, 'date' => $data['date'], 'priority' => $data['priority'], 'quote_number' => $data['quote_number'] ?? null, 'notes' => $data['notes'] ?? null, 'branch_name' => $branch->name, 'region_name' => $branch->region->name, 'supplier_name' => $supplier->name, 'vehicle_plate' => $vehicle?->plate, 'subtotal_minor' => $subtotal, 'tax_basis_points' => $taxBasis, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax];
            if ($order) {
                $order->update($values);
                $order->lines()->delete();
            } else {
                $order = PurchaseOrder::create($values + ['status' => S::Draft, 'created_by' => auth()->id()]);
                $order->update(['number' => 'PR-'.now()->year.'-'.str_pad($order->id, 6, '0', STR_PAD_LEFT)]);
            }
            $order->lines()->createMany($lines);
            Audit::record('orders.saved', $order, ['branch_id' => $order->branch_id, 'total_minor' => $order->total_minor]);

            return $order->fresh('lines');
        }, 3);
    }

    private function locked(PurchaseOrder $order): PurchaseOrder
    {
        $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        Access::branch($order->branch_id);

        return $order;
    }

    private function change(PurchaseOrder $order, S $next, string $action, ?string $reason = null): void
    {
        $from = $order->status;
        $order->update(['status' => $next]);
        $order->approvals()->create(['user_id' => auth()->id(), 'from_status' => $from->value, 'to_status' => $next->value, 'action' => $action, 'reason' => $reason]);
        Audit::record($action, $order, ['branch_id' => $order->branch_id, 'from' => $from->value, 'to' => $next->value, 'reason' => $reason]);
    }

    public function action(PurchaseOrder $order, string $action, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $action, $reason) {
            $order = $this->locked($order);
            if ($action === 'submit') {
                Access::allow('orders.submit');
                $this->require($order->status === S::Draft, 'الطلب ليس مسودة.');
                $this->require((bool) $order->quote_number && $order->getMedia('quote')->isNotEmpty(), 'أدخل رقم عرض السعر وأرفق مستنده.');
                if ($order->vehicle_id) {
                    $labels = $order->getMedia('photos_before')->map(fn ($m) => $m->getCustomProperty('label'))->all();
                    $this->require(! array_diff(array_keys(config('rotana.photo_labels')), $labels), 'أكمل الصور التسع المطلوبة.');
                }
                $this->change($order, S::Accountant, 'orders.submitted');
            } elseif ($action === 'approve') {
                $permission = match ($order->status) {
                    S::Accountant => 'orders.review',S::Manager => 'orders.approve_manager',S::Supervisor => 'orders.approve_supervisor',default => null
                };
                $this->require((bool) $permission, 'الطلب ليس في مرحلة اعتماد.');
                Access::allow($permission);
                $this->require($order->created_by !== auth()->id() || auth()->user()->can('orders.approve_own'), 'لا يمكنك اعتماد طلبك الشخصي.');
                $next = match ($order->status) {
                    S::Accountant => S::Manager,S::Manager => S::Supervisor,S::Supervisor => S::Matching
                };
                $this->change($order, $next, $permission);
            } elseif (in_array($action, ['return', 'reject'])) {
                Access::allow('orders.reject');
                $this->require(in_array($order->status, [S::Accountant, S::Manager, S::Supervisor, S::Matching, S::Ready]) && ! $order->receipt, 'لا يمكن إعادة/رفض طلب بعد الاستلام أو الدفع.');
                $this->require((bool) trim($reason ?? ''), 'اذكر السبب.');
                $this->change($order, $action === 'return' ? S::Draft : S::Rejected, 'orders.'.$action, $reason);
            } elseif ($action === 'match') {
                Access::allow('orders.match');
                $this->require($order->status === S::Matching, 'الطلب ليس في مرحلة المطابقة.');
                $this->require($this->matches($order), 'توجد فروقات في الكميات أو قيمة الفاتورة أو مستندات ناقصة.');
                $this->change($order, S::Ready, 'orders.matched');
            } elseif ($action === 'close') {
                Access::allow('orders.close');
                $this->require($order->status === S::Paid, 'سجل الحوالة أولًا.');
                $this->change($order, S::Closed, 'orders.closed');
            } else {
                abort(422, 'إجراء غير معروف.');
            }

            return $order->fresh();
        }, 3);
    }

    public function matches(PurchaseOrder $order): bool
    {
        $order->load('lines', 'receipt.lines', 'invoice.lines');
        if (! $order->receipt || ! $order->invoice || $order->total_minor !== (int) $order->invoice->total_minor) {
            return false;
        }
        if (! $order->media()->whereKey($order->invoice->media_id)->where('collection_name', 'invoice')->exists()) {
            return false;
        }
        foreach ($order->lines as $line) {
            if ($line->quantity_milli !== ($order->receipt->lines->firstWhere('order_line_id', $line->id)?->quantity_milli) || $line->quantity_milli !== ($order->invoice->lines->firstWhere('order_line_id', $line->id)?->quantity_milli)) {
                return false;
            }
        }

        return true;
    }

    private function quantities(PurchaseOrder $order, array $lines, bool $limited): array
    {
        $order->load('lines');
        $this->require(count($lines) === $order->lines->count(), 'أرسل كمية لكل بند في الطلب.');
        $ids = array_column($lines, 'order_line_id');
        $this->require(count(array_unique($ids)) === count($ids) && ! array_diff($order->lines->modelKeys(), $ids), 'بنود المستند لا تطابق الطلب.');
        $out = [];
        foreach ($lines as $l) {
            $q = Amounts::scaled($l['quantity'], 3);
            $line = $order->lines->firstWhere('id', (int) $l['order_line_id']);
            $this->require($line && (! $limited || $q <= $line->quantity_milli), 'كمية الاستلام أكبر من الكمية المعتمدة.');
            if ($order->category === 'stock') {
                $this->require($q % 1000 === 0, 'كمية المخزون يجب أن تكون عددًا صحيحًا.');
            }
            $out[] = ['order_line_id' => $line->id, 'quantity_milli' => $q];
        }

        return $out;
    }

    public function receive(PurchaseOrder $order, array $data): PurchaseOrder
    {
        Access::allow('receipts.manage');

        return DB::transaction(function () use ($order, $data) {
            $order = $this->locked($order);
            $this->require(in_array($order->status, [S::Matching, S::Ready]), 'لا يمكن تعديل الاستلام في هذه المرحلة.');
            $lines = $this->quantities($order, $data['lines'], true);
            $receipt = $order->receipt()->with('lines')->first();
            $correction = (bool) $receipt;
            if ($order->category === 'stock') {
                $warehouse = Warehouse::findOrFail($order->warehouse_id);
                Access::branch($warehouse->branch_id);
                $deltas = [];
                foreach ($lines as $line) {
                    $ol = $order->lines->firstWhere('id', $line['order_line_id']);
                    $old = $receipt ? ($receipt->lines->firstWhere('order_line_id', $ol->id)?->quantity_milli ?? 0) : 0;
                    $deltas[] = ['item_id' => $ol->item_id, 'quantity_milli' => $line['quantity_milli'] - $old, 'unit_cost_minor' => $ol->unit_price_minor];
                }
                $this->inventory->receipt($warehouse->id, $deltas, $order->id, $correction);
            }
            if (! $receipt) {
                $receipt = $order->receipt()->create(['number' => 'RCV-'.$order->number, 'warehouse_id' => $order->warehouse_id, 'created_by' => auth()->id(), 'date' => $data['date']]);
            } else {
                $receipt->update(['date' => $data['date']]);
                $receipt->lines()->delete();
            }
            $receipt->lines()->createMany($lines);
            if ($order->status === S::Ready) {
                $this->change($order, S::Matching, 'orders.rematch_required');
            }Audit::record($correction ? 'receipts.corrected' : 'receipts.created', $order, ['branch_id' => $order->branch_id, 'quantities' => $lines]);

            return $order->fresh();
        }, 3);
    }

    public function invoice(PurchaseOrder $order, array $data): PurchaseOrder
    {
        Access::allow('invoices.manage');

        return DB::transaction(function () use ($order, $data) {
            $order = $this->locked($order);
            $this->require(in_array($order->status, [S::Matching, S::Ready]), 'لا يمكن تعديل الفاتورة بعد التحويل.');
            $this->require($order->media()->whereKey($data['media_id'])->where('collection_name', 'invoice')->exists(), 'ملف الفاتورة لا يخص هذا الطلب.');
            $lines = $this->quantities($order, $data['lines'], false);
            $invoice = $order->invoice()->updateOrCreate([], ['supplier_id' => $order->supplier_id, 'number' => $data['number'], 'date' => $data['date'], 'total_minor' => Amounts::scaled($data['total']), 'media_id' => $data['media_id'], 'created_by' => auth()->id()]);
            $invoice->lines()->delete();
            $invoice->lines()->createMany($lines);
            if ($order->status === S::Ready) {
                $this->change($order, S::Matching, 'orders.rematch_required');
            }Audit::record('invoices.saved', $order, ['branch_id' => $order->branch_id, 'number' => $data['number']]);

            return $order->fresh();
        }, 3);
    }

    public function pay(PurchaseOrder $order, array $data): PurchaseOrder
    {
        Access::allow('payments.create');

        return DB::transaction(function () use ($order, $data) {
            $order = $this->locked($order);
            $this->require($order->status === S::Ready && ! $order->payment, 'يجب أن يكون الطلب جاهزًا للتحويل وغير مدفوع.');
            $this->require($this->matches($order), 'المطابقة لم تعد صحيحة.');
            $this->require($order->total_minor === Amounts::scaled($data['amount']), 'قيمة الحوالة تختلف عن إجمالي الطلب.');
            $this->require($order->media()->whereKey($data['media_id'])->where('collection_name', 'proof')->exists(), 'ارفع إثبات تحويل يخص الطلب.');
            $payment = $order->payment()->create(['reference' => $data['reference'], 'date' => $data['date'], 'amount_minor' => $order->total_minor, 'media_id' => $data['media_id'], 'created_by' => auth()->id()]);
            $this->change($order, S::Paid, 'payments.created', $payment->reference);

            return $order->fresh();
        }, 3);
    }
}
