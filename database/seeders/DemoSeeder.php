<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\PurchaseOrder;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PurchasingService;
use App\Support\Amounts;
use App\Support\DemoPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo data is forbidden in production.');
        }
        $password = DemoPassword::resolve();
        if (strlen($password ?? '') < 12) {
            throw new \RuntimeException('Set DEMO_PASSWORD to at least 12 characters.');
        }
        if (PurchaseOrder::where('notes', 'DEMO-SEED')->exists()) {
            $this->command?->warn('Demo data already exists; skipped.');

            return;
        }
        $branches = [];
        foreach (['الرياض', 'جدة', 'الدمام'] as $i => $name) {
            $region = Region::firstOrCreate(['name' => $name]);
            $branches[] = Branch::firstOrCreate(['code' => 'B'.($i + 1)], ['name' => 'فرع '.$name, 'region_id' => $region->id]);
        }
        $center = CostCenter::firstOrCreate(['code' => 'CC-01'], ['name' => 'تشغيل الأسطول']);
        foreach (['admin', 'employee', 'accountant', 'manager', 'supervisor', 'treasurer', 'warehouse', 'maintenance', 'auditor'] as $role) {
            $u = User::firstOrCreate(['email' => $role.'@rotana.test'], ['name' => ['admin' => 'مدير النظام', 'employee' => 'موظف الفرع', 'accountant' => 'المحاسب', 'manager' => 'مدير الإدارة', 'supervisor' => 'المشرف', 'treasurer' => 'أمين الخزينة', 'warehouse' => 'أمين المخزن', 'maintenance' => 'فني الصيانة', 'auditor' => 'المراجع'][$role], 'password' => Hash::make($password), 'active' => true, 'all_branches' => in_array($role, ['admin', 'auditor'])]);
            $u->syncRoles([$role]);
            $u->branches()->sync([$branches[0]->id]);
        }
        Auth::login(User::where('email', 'admin@rotana.test')->firstOrFail());
        $warehouses = [];
        $vehicles = [];
        foreach ($branches as $i => $b) {
            $warehouses[] = Warehouse::firstOrCreate(['code' => 'WH'.($i + 1)], ['name' => 'المخزن - '.$b->name, 'branch_id' => $b->id]);
            $vehicles[] = Vehicle::firstOrCreate(['plate_key' => '123'.($i + 1).'ABC'], ['plate' => 'أ ب ج ١٢٣'.($i + 1), 'model' => ['تويوتا كامري', 'هيونداي إلنترا', 'تويوتا هايلكس'][$i], 'year' => 2024, 'color' => 'أبيض', 'odometer' => 45000, 'branch_id' => $b->id, 'cost_center_id' => $center->id]);
        }
        $supplier = Supplier::firstOrCreate(['code' => 'SUP-001'], ['name' => 'مؤسسة قطع الغيار التجريبية', 'phone' => '0500000000', 'email' => 'supplier@example.test']);
        Supplier::firstOrCreate(['code' => 'SUP-002'], ['name' => 'مركز الصيانة التجريبي']);
        $items = [];
        foreach (['إطار 205/65 R15', 'بطارية 70 أمبير', 'زيت محرك', 'فلتر زيت', 'أجور صيانة'] as $i => $name) {
            $items[] = Item::firstOrCreate(['sku' => 'ITM-00'.($i + 1)], ['name' => $name, 'unit' => 'قطعة', 'track_stock' => $i < 4, 'unit_cost_minor' => [25000, 35000, 4500, 2500, 15000][$i], 'minimum_milli' => 10000]);
        }
        $inventory = app(InventoryService::class);
        $service = app(PurchasingService::class);
        foreach ($warehouses as $wh) {
            $inventory->move(['request_key' => (string) Str::uuid(), 'type' => 'adjust', 'warehouse_id' => $wh->id, 'date' => today()->toDateString(), 'notes' => 'رصيد افتتاحي تجريبي', 'lines' => array_map(fn ($item) => ['item_id' => $item->id, 'quantity' => 50], array_slice($items, 0, 4))]);
        }
        foreach (['draft', 'accountant', 'manager', 'supervisor', 'matching', 'ready', 'paid', 'closed', 'rejected', 'difference'] as $i => $target) {
            $order = $service->save(['category' => 'stock', 'branch_id' => $branches[0]->id, 'cost_center_id' => $center->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouses[0]->id, 'date' => today()->subDays(10 - $i)->toDateString(), 'priority' => 'normal', 'quote_number' => 'QT-DEMO-'.($i + 1), 'notes' => 'DEMO-SEED', 'tax_percent' => '15', 'lines' => [['item_id' => $items[0]->id, 'quantity' => '4', 'unit_price' => '250'], ['item_id' => $items[2]->id, 'quantity' => '10', 'unit_price' => '45']]]);
            $order->addMedia(database_path('seeders/fixtures/sample.pdf'))->preservingOriginal()->toMediaCollection('quote');
            if ($target === 'draft') {
                continue;
            }
            $order = $service->action($order, 'submit');
            if ($target === 'accountant') {
                continue;
            }
            if ($target === 'rejected') {
                $service->action($order, 'reject', 'عرض سعر يحتاج إلى إعادة تسعير');

                continue;
            }
            $order = $service->action($order, 'approve');
            if ($target === 'manager') {
                continue;
            }
            $order = $service->action($order, 'approve');
            if ($target === 'supervisor') {
                continue;
            }
            $order = $service->action($order, 'approve');
            $lines = $order->lines->map(fn ($l) => ['order_line_id' => $l->id, 'quantity' => Amounts::quantity($l->quantity_milli)])->all();
            $service->receive($order, ['date' => today()->toDateString(), 'lines' => $lines]);
            $media = $order->addMedia(database_path('seeders/fixtures/sample.pdf'))->preservingOriginal()->toMediaCollection('invoice');
            $service->invoice($order, ['date' => today()->toDateString(), 'lines' => $lines, 'number' => 'INV-DEMO-'.($i + 1), 'total' => Amounts::money($order->total_minor + ($target === 'difference' ? 1000 : 0)), 'media_id' => $media->id]);
            if (in_array($target, ['matching', 'difference'])) {
                continue;
            }
            $order = $service->action($order, 'match');
            if ($target === 'ready') {
                continue;
            }
            $proof = $order->addMedia(database_path('seeders/fixtures/sample.pdf'))->preservingOriginal()->toMediaCollection('proof');
            $order = $service->pay($order, ['date' => today()->toDateString(), 'reference' => 'TR-DEMO-'.($i + 1), 'amount' => Amounts::money($order->total_minor), 'media_id' => $proof->id]);
            if ($target === 'closed') {
                $service->action($order, 'close');
            }
        }
        foreach (['maintenance', 'damage', 'parts', 'utilities', 'branches', 'quotes'] as $idx => $category) {
            $o = $service->save(['category' => $category, 'branch_id' => $branches[0]->id, 'cost_center_id' => $center->id, 'supplier_id' => $supplier->id, 'vehicle_id' => $vehicles[0]->id, 'date' => today()->toDateString(), 'priority' => 'normal', 'quote_number' => 'QT-CAT-'.$idx, 'notes' => 'DEMO-SEED', 'tax_percent' => '15', 'lines' => [['item_id' => $items[4]->id, 'quantity' => '1', 'unit_price' => '150']]]);
            $o->addMedia(database_path('seeders/fixtures/sample.pdf'))->preservingOriginal()->toMediaCollection('quote');
            if ($o->vehicle_id) {
                foreach (['front' => 'front', 'back' => 'back', 'right' => 'right', 'left' => 'left', 'angle_front' => 'angle-front', 'angle_back' => 'angle-back', 'interior' => 'inside', 'odometer' => 'meter', 'damage' => 'damage'] as $label => $file) {
                    $o->addMedia(public_path('assets/reference/car-'.$file.'.png'))->preservingOriginal()->withCustomProperties(['label' => $label])->toMediaCollection('photos_before');
                }
            }
            $service->action($o, 'submit');
        }
        $issue = $inventory->move(['request_key' => (string) Str::uuid(), 'type' => 'issue', 'warehouse_id' => $warehouses[0]->id, 'vehicle_id' => $vehicles[0]->id, 'odometer' => 45100, 'date' => today()->toDateString(), 'notes' => 'تركيب إطارات', 'lines' => [['item_id' => $items[0]->id, 'quantity' => 4]]]);
        $inventory->move(['request_key' => (string) Str::uuid(), 'type' => 'return', 'warehouse_id' => $warehouses[0]->id, 'source_movement_id' => $issue->id, 'date' => today()->toDateString(), 'notes' => 'مرتجع غير مستخدم', 'lines' => [['item_id' => $items[0]->id, 'quantity' => 1]]]);
        $inventory->move(['request_key' => (string) Str::uuid(), 'type' => 'transfer', 'warehouse_id' => $warehouses[0]->id, 'destination_warehouse_id' => $warehouses[1]->id, 'date' => today()->toDateString(), 'notes' => 'تحويل للفروع', 'lines' => [['item_id' => $items[1]->id, 'quantity' => 5]]]);
        foreach (['pending', 'waiting_parts', 'in_progress', 'completed', 'closed'] as $i => $status) {
            MaintenanceCard::create(['number' => 'MC-DEMO-'.($i + 1), 'vehicle_id' => $vehicles[0]->id, 'branch_id' => $branches[0]->id, 'created_by' => auth()->id(), 'date' => today()->toDateString(), 'type' => 'صيانة دورية', 'status' => $status, 'odometer' => 45100, 'notes' => 'كارت تجريبي لعرض مراحل الصيانة']);
        }Auth::logout();
    }
}
