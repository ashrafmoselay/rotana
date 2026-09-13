<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\OrderLine;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Support\Amounts;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RotanaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Session::start();
        $token = csrf_token();
        $this->withSession(['_token' => $token]);
        $this->withHeader('X-CSRF-TOKEN', $token);
        config(['rotana.demo_password' => 'Testing-Rotana-2026']);
        Storage::fake('private_media');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
    }

    private function ordersDataTableParams(array $extra = []): array
    {
        return array_replace_recursive([
            'draw' => 7,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 0, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'number', 'name' => 'number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'date', 'name' => 'date', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'branch_name', 'name' => 'branch_name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'supplier_name', 'name' => 'supplier_name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'vehicle_plate', 'name' => 'vehicle_plate', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'total', 'name' => 'total', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'status_label', 'name' => 'status_label', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'id', 'name' => 'id', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function ordersDataTable(array $params = [])
    {
        return $this->getJson('/api/orders?'.http_build_query($this->ordersDataTableParams($params)));
    }

    private function inventoryBalancesDataTableParams(array $extra = []): array
    {
        return array_replace_recursive([
            'warehouse_id' => 1,
            'draw' => 11,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 1, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'sku', 'name' => 'sku', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'quantity_milli', 'name' => 'quantity_milli', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'minimum_milli', 'name' => 'minimum_milli', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'stock_state', 'name' => 'stock_state', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function inventoryBalancesDataTable(array $params = [])
    {
        return $this->getJson('/api/inventory/balances?'.http_build_query($this->inventoryBalancesDataTableParams($params)));
    }

    private function stockMovementsDataTableParams(array $extra = []): array
    {
        return array_replace_recursive([
            'draw' => 12,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 0, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'number', 'name' => 'number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'date', 'name' => 'date', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'type', 'name' => 'type', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'warehouse.name', 'name' => 'warehouse.name', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'vehicle.plate', 'name' => 'vehicle.plate', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'lines', 'name' => 'lines', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'notes', 'name' => 'notes', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function stockMovementsDataTable(array $params = [])
    {
        return $this->getJson('/api/inventory/movements?'.http_build_query($this->stockMovementsDataTableParams($params)));
    }

    private function createStockMovementForWarehouse(int $warehouseId, array $extra = []): StockMovement
    {
        $line = [
            'item_id' => $extra['item_id'] ?? Item::where('track_stock', true)->firstOrFail()->id,
            'quantity_milli' => $extra['quantity_milli'] ?? -1000,
            'unit_cost_minor' => $extra['unit_cost_minor'] ?? 25000,
        ];
        $header = array_diff_key($extra, array_flip(['item_id', 'quantity_milli', 'unit_cost_minor']));

        $movement = StockMovement::create(array_replace([
            'request_key' => (string) Str::uuid(),
            'type' => 'issue',
            'warehouse_id' => $warehouseId,
            'vehicle_id' => Vehicle::where('branch_id', Warehouse::findOrFail($warehouseId)->branch_id)->value('id'),
            'date' => today()->toDateString(),
            'notes' => 'Inventory listing test',
            'created_by' => User::where('email', 'admin@rotana.test')->firstOrFail()->id,
        ], $header));

        $movement->update(['number' => $extra['number'] ?? 'ST-TABLE-'.str_pad($movement->id, 6, '0', STR_PAD_LEFT)]);
        $movement->lines()->create($line);

        return $movement;
    }

    private function createOrderForBranch(int $branchId, array $extra = []): PurchaseOrder
    {
        $branch = Branch::with('region')->findOrFail($branchId);
        $supplier = Supplier::findOrFail($extra['supplier_id'] ?? 1);

        return PurchaseOrder::create(array_replace([
            'number' => 'PO-TABLE-'.$branchId.'-'.Str::random(8),
            'category' => 'stock',
            'status' => 'draft',
            'branch_id' => $branch->id,
            'cost_center_id' => CostCenter::firstOrFail()->id,
            'supplier_id' => $supplier->id,
            'vehicle_id' => null,
            'warehouse_id' => Warehouse::where('branch_id', $branch->id)->firstOrFail()->id,
            'created_by' => User::where('email', 'admin@rotana.test')->firstOrFail()->id,
            'date' => today()->toDateString(),
            'priority' => 'normal',
            'quote_number' => 'QT-TABLE-'.$branchId,
            'notes' => 'PO-DATATABLE-TEST',
            'branch_name' => $branch->name,
            'region_name' => $branch->region->name,
            'supplier_name' => $supplier->name,
            'vehicle_plate' => null,
            'subtotal_minor' => 10000,
            'tax_basis_points' => 1500,
            'tax_minor' => 1500,
            'total_minor' => 11500,
        ], $extra));
    }

    private function validOrderPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'category' => 'stock',
            'branch_id' => 1,
            'cost_center_id' => 1,
            'supplier_id' => 1,
            'warehouse_id' => 1,
            'vehicle_id' => null,
            'maintenance_card_id' => null,
            'date' => today()->toDateString(),
            'priority' => 'normal',
            'quote_number' => 'TEST-Q',
            'notes' => 'ملاحظات عربية لاختبار الحفظ',
            'tax_percent' => '15',
            'lines' => [
                ['item_id' => 1, 'quantity' => '2', 'unit_price' => '10.01'],
            ],
        ], $overrides);
    }

    public function test_seed_has_every_order_and_card_stage(): void
    {
        foreach (array_keys(['draft' => 1, 'accountant' => 1, 'manager' => 1, 'supervisor' => 1, 'matching' => 1, 'ready' => 1, 'paid' => 1, 'closed' => 1, 'rejected' => 1]) as $s) {
            $this->assertDatabaseHas('purchase_orders', ['status' => $s]);
        }$this->assertDatabaseCount('users', 9);
        $this->assertDatabaseCount('maintenance_cards', 5);
    }

    public function test_authenticated_views_and_datatables(): void
    {
        $this->get('/')->assertOk()->assertSee('server.js');
        foreach (['/api/dashboard', '/api/lookups', '/api/orders?draw=1&start=0&length=10', '/api/admin/users', '/api/admin/roles', '/api/admin/activity', '/api/cards', '/api/inventory/balances?warehouse_id=1'] as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->getJson('/api/orders')->assertJsonStructure(['data', 'recordsTotal', 'recordsFiltered']);
        $lookups = $this->getJson('/api/lookups')->assertOk()->json();
        $this->assertSame('مدير النظام', data_get($lookups, 'ui.role_labels.admin'));
        $this->assertSame('عرض طلبات الشراء', $lookups['ui']['permission_catalog']['orders.view']['label'] ?? null);
        $roles = $this->getJson('/api/admin/roles')->assertOk()->json();
        $this->assertSame('اعتماد المطابقة', $roles['permission_catalog']['orders.match']['label'] ?? null);
        $this->assertSame('لوحة التحكم', data_get($roles, 'permission_groups.0.label'));
    }

    public function test_purchase_orders_listing_guards_permission_and_returns_datatables_shape(): void
    {
        auth()->logout();
        $this->ordersDataTable()->assertUnauthorized();

        $blocked = User::create(['name' => 'No order access', 'email' => 'no-orders@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->ordersDataTable()->assertForbidden();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->ordersDataTable()
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_purchase_orders_listing_respects_branch_scope_for_limited_and_global_users(): void
    {
        $foreign = $this->createOrderForBranch(2, ['number' => 'PO-BRANCH-FOREIGN']);
        $local = PurchaseOrder::where('branch_id', 1)->firstOrFail();
        $local->update(['number' => 'PO-BRANCH-LOCAL']);

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $limited = $this->ordersDataTable(['length' => 100])->assertOk()->json();
        $this->assertContains('PO-BRANCH-LOCAL', collect($limited['data'])->pluck('number')->all());
        $this->assertNotContains($foreign->number, collect($limited['data'])->pluck('number')->all());

        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
        $global = $this->ordersDataTable(['length' => 100])->assertOk()->json();
        $this->assertContains($foreign->number, collect($global['data'])->pluck('number')->all());
    }

    public function test_purchase_orders_listing_caps_page_length_and_handles_empty_results(): void
    {
        $response = $this->ordersDataTable(['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($response['data']));

        $empty = $this->ordersDataTable(['search' => ['value' => 'NO-SUCH-ORDER-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
        $this->assertSame(0, $empty['recordsFiltered']);
        $this->assertSame([], $empty['data']);
    }

    public function test_purchase_orders_listing_search_does_not_escape_branch_scope(): void
    {
        $foreign = $this->createOrderForBranch(2, ['number' => 'PO-SEARCH-FOREIGN', 'supplier_name' => 'مورد خارج النطاق']);

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $response = $this->ordersDataTable(['search' => ['value' => $foreign->number, 'regex' => 'false']])->assertOk()->json();

        $this->assertSame(0, $response['recordsFiltered']);
        $this->assertSame([], $response['data']);
    }

    public function test_purchase_orders_listing_filters_by_status_supplier_and_authorized_branch(): void
    {
        $supplier = Supplier::where('code', 'SUP-002')->firstOrFail();
        $match = $this->createOrderForBranch(1, ['number' => 'PO-FILTER-MATCH', 'status' => 'ready', 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]);
        $this->createOrderForBranch(1, ['number' => 'PO-FILTER-OTHER', 'status' => 'draft']);

        $response = $this->ordersDataTable(['status' => 'ready', 'supplier_id' => $supplier->id, 'branch_id' => 1, 'length' => 100])->assertOk()->json();
        $numbers = collect($response['data'])->pluck('number')->all();

        $this->assertContains($match->number, $numbers);
        $this->assertNotContains('PO-FILTER-OTHER', $numbers);
    }

    public function test_purchase_orders_summary_uses_listing_filters(): void
    {
        $stockReadyBefore = PurchaseOrder::where('status', 'ready')->where('category', 'stock')->count();
        $maintenanceReadyBefore = PurchaseOrder::where('status', 'ready')->where('category', 'maintenance')->count();
        $this->createOrderForBranch(1, ['category' => 'stock', 'status' => 'ready']);
        $this->createOrderForBranch(1, ['category' => 'maintenance', 'status' => 'draft']);

        $summary = $this->getJson('/api/orders/summary?status=ready')->assertOk()->json();
        $counts = collect($summary['categories'])->pluck('count', 'category');

        $this->assertSame($stockReadyBefore + 1, $counts['stock']);
        $this->assertSame($maintenanceReadyBefore, $counts['maintenance']);
    }

    public function test_purchase_orders_listing_orders_by_supported_columns_and_searches_supported_fields(): void
    {
        $low = $this->createOrderForBranch(1, ['number' => 'PO-SORT-LOW', 'supplier_name' => 'مورد ترتيب ألف', 'total_minor' => 1000]);
        $high = $this->createOrderForBranch(1, ['number' => 'PO-SORT-HIGH', 'supplier_name' => 'مورد ترتيب باء', 'total_minor' => 9000]);

        $searched = $this->ordersDataTable(['search' => ['value' => $high->supplier_name, 'regex' => 'false'], 'length' => 100])->assertOk()->json();
        $this->assertContains($high->number, collect($searched['data'])->pluck('number')->all());
        $this->assertNotContains($low->number, collect($searched['data'])->pluck('number')->all());

        $ordered = $this->ordersDataTable(['order' => [['column' => 5, 'dir' => 'asc']], 'length' => 100])->assertOk()->json('data');
        $numbers = collect($ordered)->pluck('number');
        $this->assertLessThan($numbers->search($high->number), $numbers->search($low->number));
    }

    public function test_purchase_orders_listing_handles_unusual_search_and_keeps_display_formats(): void
    {
        $order = PurchaseOrder::firstOrFail();
        $order->update(['number' => 'PO-FORMAT-001', 'total_minor' => 123456]);

        $this->ordersDataTable(['search' => ['value' => str_repeat('%_', 150), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);

        $row = collect($this->ordersDataTable(['search' => ['value' => 'PO-FORMAT-001', 'regex' => 'false']])->assertOk()->json('data'))->first();
        $this->assertSame('PO-FORMAT-001', $row['number']);
        $this->assertSame('1234.56', $row['total']);
        $this->assertIsString($row['total']);
        $this->assertIsString($row['status_label']);
        $this->assertArrayNotHasKey('actions', $row);
    }

    public function test_inventory_balances_listing_guards_permission_and_returns_datatables_shape(): void
    {
        auth()->logout();
        $this->inventoryBalancesDataTable()->assertUnauthorized();

        $blocked = User::create(['name' => 'No inventory access', 'email' => 'no-inventory@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->inventoryBalancesDataTable()->assertForbidden();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->inventoryBalancesDataTable()
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_inventory_balances_listing_scopes_searches_orders_caps_and_keeps_milli_units(): void
    {
        $tracked = Item::where('track_stock', true)->firstOrFail();
        $tracked->update(['sku' => 'INV-BAL-LOCAL', 'name' => 'رصيد محلي دقيق', 'minimum_milli' => 10000]);
        StockBalance::updateOrCreate(['warehouse_id' => 1, 'item_id' => $tracked->id], ['quantity_milli' => 12345]);

        foreach (range(1, 110) as $i) {
            Item::create(['sku' => 'INV-CAP-'.$i, 'name' => 'صنف حد الصفحة '.$i, 'unit' => 'قطعة', 'track_stock' => true, 'unit_cost_minor' => 1000, 'minimum_milli' => 1000]);
        }

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->getJson('/api/inventory/balances?'.http_build_query($this->inventoryBalancesDataTableParams(['warehouse_id' => 2])))->assertForbidden();

        $searched = $this->inventoryBalancesDataTable(['search' => ['value' => 'INV-BAL-LOCAL', 'regex' => 'false']])->assertOk()->json();
        $row = collect($searched['data'])->firstWhere('sku', 'INV-BAL-LOCAL');
        $this->assertNotNull($row);
        $this->assertSame(12345, $row['quantity_milli']);
        $this->assertSame(10000, $row['minimum_milli']);
        $this->assertArrayNotHasKey('actions', $row);

        $empty = $this->inventoryBalancesDataTable(['search' => ['value' => 'NO-SUCH-BALANCE-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
        $this->assertSame(0, $empty['recordsFiltered']);
        $this->assertSame([], $empty['data']);

        $this->inventoryBalancesDataTable(['search' => ['value' => str_repeat('%_', 150), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);

        $capped = $this->inventoryBalancesDataTable(['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($capped['data']));

        $adminWarehouse = $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail())
            ->getJson('/api/inventory/balances?'.http_build_query($this->inventoryBalancesDataTableParams(['warehouse_id' => 2])))
            ->assertOk()
            ->json();
        $this->assertArrayHasKey('data', $adminWarehouse);
    }

    public function test_stock_movements_listing_guards_permission_and_returns_datatables_shape(): void
    {
        auth()->logout();
        $this->stockMovementsDataTable()->assertUnauthorized();

        $blocked = User::create(['name' => 'No movement access', 'email' => 'no-movements@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->stockMovementsDataTable()->assertForbidden();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->stockMovementsDataTable()
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_stock_movements_listing_scopes_filters_searches_orders_caps_and_keeps_line_precision(): void
    {
        $local = $this->createStockMovementForWarehouse(1, ['number' => 'ST-LOCAL-SCOPE', 'type' => 'issue', 'date' => '2026-01-02', 'notes' => 'حركة محلية قابلة للبحث', 'quantity_milli' => -1234]);
        $foreign = $this->createStockMovementForWarehouse(2, ['number' => 'ST-FOREIGN-SCOPE', 'type' => 'transfer', 'date' => '2026-01-03', 'notes' => 'حركة خارج النطاق']);

        foreach (range(1, 105) as $i) {
            $this->createStockMovementForWarehouse(1, ['number' => 'ST-CAP-'.$i, 'notes' => 'حركة حد الصفحة '.$i]);
        }

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->getJson('/api/inventory/movements?'.http_build_query($this->stockMovementsDataTableParams(['warehouse_id' => 2])))->assertForbidden();

        $leakSearch = $this->stockMovementsDataTable(['search' => ['value' => $foreign->number, 'regex' => 'false']])->assertOk()->json();
        $this->assertSame(0, $leakSearch['recordsFiltered']);
        $this->assertSame([], $leakSearch['data']);

        $filtered = $this->stockMovementsDataTable(['warehouse_id' => 1, 'type' => 'issue', 'search' => ['value' => 'حركة محلية', 'regex' => 'false'], 'length' => 100])->assertOk()->json();
        $row = collect($filtered['data'])->firstWhere('number', $local->number);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['warehouse_id']);
        $this->assertSame('issue', $row['type']);
        $this->assertSame(-1234, $row['lines'][0]['quantity_milli']);
        $this->assertArrayNotHasKey('actions', $row);

        $ordered = $this->stockMovementsDataTable([
            'warehouse_id' => 1,
            'search' => ['value' => 'ST-LOCAL-SCOPE', 'regex' => 'false'],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ])->assertOk()->json();
        $this->assertSame($local->number, $ordered['data'][0]['number'] ?? null);

        $this->stockMovementsDataTable(['search' => ['value' => str_repeat('%_', 150), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);

        $capped = $this->stockMovementsDataTable(['warehouse_id' => 1, 'length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($capped['data']));

        $admin = $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail())
            ->stockMovementsDataTable(['search' => ['value' => $foreign->number, 'regex' => 'false']])
            ->assertOk()
            ->json();
        $this->assertContains($foreign->number, collect($admin['data'])->pluck('number')->all());
    }

    public function test_inventory_item_filter_includes_only_warehouse_items_and_matching_movement_lines(): void
    {
        $item = Item::create(['sku' => 'REPORT-ITEM', 'name' => 'صنف التقرير', 'unit' => 'قطعة', 'track_stock' => true, 'active' => false]);
        $other = Item::create(['sku' => 'REPORT-OTHER', 'name' => 'صنف آخر', 'unit' => 'قطعة', 'track_stock' => true]);
        $local = $this->createStockMovementForWarehouse(1, ['item_id' => $item->id, 'quantity_milli' => -1234]);
        $local->lines()->create(['item_id' => $other->id, 'quantity_milli' => -2000, 'unit_cost_minor' => 0]);
        $this->createStockMovementForWarehouse(2, ['item_id' => $other->id]);
        $incoming = $this->createStockMovementForWarehouse(2, ['item_id' => $item->id, 'type' => 'transfer', 'destination_warehouse_id' => 1]);
        $balanceOnly = Item::create(['sku' => 'REPORT-ZERO', 'name' => 'رصيد صفر', 'unit' => 'قطعة', 'track_stock' => true]);
        StockBalance::create(['warehouse_id' => 1, 'item_id' => $balanceOnly->id, 'quantity_milli' => 0]);
        $foreignOnly = Item::create(['sku' => 'REPORT-FOREIGN', 'name' => 'صنف خارج المخزن', 'unit' => 'قطعة', 'track_stock' => true]);
        $this->createStockMovementForWarehouse(2, ['item_id' => $foreignOnly->id]);

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $ids = collect($this->getJson('/api/inventory/items?warehouse_id=1')->assertOk()->json())->pluck('id');
        $this->assertContains($item->id, $ids);
        $this->assertContains($balanceOnly->id, $ids);
        $this->assertNotContains($foreignOnly->id, $ids);
        $this->getJson('/api/inventory/items?warehouse_id=2')->assertForbidden();
        $rows = $this->stockMovementsDataTable(['warehouse_id' => 1, 'item_id' => $item->id])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$local->id, $incoming->id], array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertCount(1, $row['lines']);
            $this->assertSame($item->id, $row['lines'][0]['item_id']);
        }
        $this->inventoryBalancesDataTable(['item_id' => $balanceOnly->id])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity_milli', 0);
        $this->stockMovementsDataTable(['item_id' => 'invalid'])->assertUnprocessable();
    }

    public function test_inventory_movement_excel_exports_all_filtered_lines_and_signed_quantities(): void
    {
        $item = Item::create(['sku' => 'EXPORT-ITEM', 'name' => '=HYPERLINK("evil")', 'unit' => 'قطعة', 'track_stock' => true]);
        $outgoing = $this->createStockMovementForWarehouse(1, ['item_id' => $item->id, 'quantity_milli' => -1234, 'notes' => 'EXPORT-MATCH']);
        $outgoing->lines()->create(['item_id' => Item::where('id', '!=', $item->id)->firstOrFail()->id, 'quantity_milli' => -9000, 'unit_cost_minor' => 0]);
        $incoming = $this->createStockMovementForWarehouse(2, ['item_id' => $item->id, 'type' => 'transfer', 'destination_warehouse_id' => 1, 'quantity_milli' => -2500, 'notes' => 'EXPORT-MATCH']);
        $this->createStockMovementForWarehouse(1, ['item_id' => $item->id, 'notes' => 'EXCLUDED']);
        $this->createStockMovementForWarehouse(2, ['item_id' => $item->id, 'notes' => 'EXPORT-MATCH']);
        $params = ['warehouse_id' => 1, 'item_id' => $item->id, 'length' => 1, 'start' => 1, 'search' => ['value' => 'EXPORT-MATCH'], 'export' => 1];
        $response = $this->get('/api/inventory/movements?'.http_build_query($this->stockMovementsDataTableParams($params)))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $rows = array_slice($sheet->toArray(), 1);
        $this->assertCount(2, $rows);
        $byNumber = collect($rows)->keyBy(fn ($row) => $row[0]);
        $this->assertEquals(-1.234, $byNumber[$outgoing->number][9]);
        $this->assertEquals(2.5, $byNumber[$incoming->number][9]);
        $this->assertSame('تحويل بين المخازن', $byNumber[$incoming->number][2]);
        $this->assertSame('s', $sheet->getCell('H2')->getDataType());
        $this->assertSame($item->name, $sheet->getCell('H2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_inventory_report_endpoints_require_permissions_and_branch_access(): void
    {
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->getJson('/api/inventory/movements?warehouse_id=2&export=1')->assertForbidden();
        $blocked = User::create(['name' => 'No report access', 'email' => 'no-report@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->getJson('/api/inventory/items?warehouse_id=1')->assertForbidden();
        $this->getJson('/api/inventory/movements?warehouse_id=1&export=1')->assertForbidden();
        $blocked->givePermissionTo('inventory.view');
        $this->getJson('/api/inventory/items?warehouse_id=1')->assertOk();
        $this->getJson('/api/inventory/movements?warehouse_id=1&export=1')->assertForbidden();
        auth()->logout();
        $this->getJson('/api/inventory/items?warehouse_id=1')->assertUnauthorized();
        $this->getJson('/api/inventory/movements?warehouse_id=1&export=1')->assertUnauthorized();
    }

    public function test_permissions_and_branch_boundaries(): void
    {
        $u = User::where('email', 'employee@rotana.test')->first();
        $this->actingAs($u);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/orders/'.PurchaseOrder::where('status', 'accountant')->first()->id.'/actions/approve')->assertForbidden();
        $foreign = Vehicle::where('branch_id', 2)->first();
        $this->putJson('/api/masters/vehicles/'.$foreign->id, [])->assertForbidden();
        $this->getJson('/api/inventory/balances?warehouse_id=2')->assertForbidden();
    }

    public function test_guest_and_inactive_user_cannot_access(): void
    {
        auth()->logout();
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/orders')->assertUnauthorized();
        $u = User::first();
        $u->update(['active' => false]);
        $this->actingAs($u)->getJson('/api/orders')->assertForbidden();
    }

    public function test_invoice_difference_prevents_payment(): void
    {
        $o = PurchaseOrder::where('status', 'matching')->latest('id')->first();
        $this->postJson('/api/orders/'.$o->id.'/actions/match')->assertUnprocessable();
        $this->postJson('/api/orders/'.$o->id.'/payment', ['reference' => 'BAD', 'date' => today()->toDateString(), 'amount' => '1', 'media_id' => $o->invoice->media_id])->assertUnprocessable();
        $this->assertNull($o->fresh()->payment);
    }

    public function test_payment_then_close_and_duplicate_protection(): void
    {
        $o = PurchaseOrder::where('status', 'ready')->first();
        $file = UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf');
        $m = $this->postJson('/api/orders/'.$o->id.'/media', ['collection' => 'proof', 'file' => $file])->assertCreated()->json('id');
        $d = ['reference' => 'NEW-TRANSFER', 'date' => today()->toDateString(), 'amount' => Amounts::money($o->total_minor), 'media_id' => $m];
        $this->postJson('/api/orders/'.$o->id.'/payment', $d)->assertOk();
        $this->postJson('/api/orders/'.$o->id.'/payment', $d)->assertUnprocessable();
        $this->postJson('/api/orders/'.$o->id.'/actions/close')->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id' => $o->id, 'status' => 'closed']);
    }

    public function test_receipt_correction_applies_only_delta(): void
    {
        $o = PurchaseOrder::where('status', 'matching')->first();
        $before = StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli');
        $lines = $o->lines->map(fn ($l) => ['order_line_id' => $l->id, 'quantity' => $l->quantity_milli / 1000])->all();
        $lines[0]['quantity'] -= 1;
        $payload = ['date' => today()->toDateString(), 'lines' => $lines];
        $this->postJson('/api/orders/'.$o->id.'/receipt', $payload)->assertOk();
        $this->assertEquals($before - 1000, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->postJson('/api/orders/'.$o->id.'/receipt', $payload)->assertOk();
        $this->assertEquals($before - 1000, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
    }

    private function movement(array $extra = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'type' => 'issue', 'warehouse_id' => 1, 'vehicle_id' => 1, 'odometer' => 45101, 'date' => today()->toDateString(), 'notes' => 'test', 'lines' => [['item_id' => 1, 'quantity' => 1]]], $extra);
    }

    public function test_stock_insufficiency_is_atomic(): void
    {
        $before = StockBalance::pluck('quantity_milli', 'id')->all();
        $count = StockMovement::count();
        $this->postJson('/api/inventory/movements', $this->movement(['lines' => [['item_id' => 1, 'quantity' => 1], ['item_id' => 2, 'quantity' => 999999]]]))->assertUnprocessable();
        $this->assertSame($before, StockBalance::pluck('quantity_milli', 'id')->all());
        $this->assertSame($count, StockMovement::count());
    }

    public function test_transfer_conserves_stock_and_uuid_cannot_repeat(): void
    {
        $before = StockBalance::where('item_id', 1)->sum('quantity_milli');
        $d = $this->movement(['type' => 'transfer', 'destination_warehouse_id' => 2]);
        $this->postJson('/api/inventory/movements', $d)->assertCreated();
        $this->postJson('/api/inventory/movements', $d)->assertUnprocessable();
        $this->assertEquals($before, StockBalance::where('item_id', 1)->sum('quantity_milli'));
    }

    public function test_return_cannot_exceed_original_issue(): void
    {
        $source = StockMovement::where('type', 'issue')->first();
        $this->postJson('/api/inventory/movements', $this->movement(['type' => 'return', 'source_movement_id' => $source->id, 'lines' => [['item_id' => 1, 'quantity' => 4]]]))->assertUnprocessable();
    }

    public function test_private_media_is_scoped_and_not_public(): void
    {
        $o = PurchaseOrder::first();
        $m = $o->getFirstMedia('quote');
        $this->get('/media/'.$m->id)->assertOk();
        $o->update(['branch_id' => 2]);
        $this->actingAs(User::where('email', 'employee@rotana.test')->first());
        $this->get('/media/'.$m->id)->assertForbidden();
        $this->getJson('/api/orders/'.$o->id)->assertForbidden();
    }

    public function test_excel_import_rolls_back_all_rows_on_failure(): void
    {
        $before = Item::count();
        $csv = "sku,name,unit,track_stock,unit_cost,minimum\nNEW-1,Valid,piece,1,12.50,2\nITM-001,Duplicate,piece,1,20,1\n";
        $file = UploadedFile::fake()->createWithContent('items.csv', $csv);
        $this->postJson('/api/excel/import/items', ['file' => $file])->assertUnprocessable();
        $this->assertEquals($before, Item::count());
        $this->assertDatabaseMissing('items', ['sku' => 'NEW-1']);
    }

    public function test_excel_export_neutralizes_formula_strings(): void
    {
        Item::first()->update(['name' => '=HYPERLINK("evil")']);
        $response = $this->get('/api/excel/export/items')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($file);
        $cell = $book->getActiveSheet()->getCell('B2');
        $this->assertSame('s', $cell->getDataType());
        $this->assertSame('=HYPERLINK("evil")', $cell->getValue());
    }

    public function test_decimal_rounding_and_last_admin_protection(): void
    {
        $this->assertSame(1501, Amounts::line(1000, 1501));
        $this->assertSame(225, Amounts::tax(1501, 1500));
        $this->assertSame('15000000000000.01', Amounts::money(1500000000000001));
        $u = auth()->user();
        $this->putJson('/api/admin/users/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'active' => false, 'all_branches' => true, 'roles' => ['admin'], 'branch_ids' => []])->assertUnprocessable();
    }

    public function test_vehicle_order_requires_all_nine_photos(): void
    {
        $o = PurchaseOrder::where('category', 'maintenance')->first();
        $o->update(['status' => 'draft']);
        $m = $o->getFirstMedia('photos_before');
        $m->delete();
        $this->postJson('/api/orders/'.$o->id.'/actions/submit')->assertUnprocessable();
        $this->postJson('/api/orders/'.$o->id.'/media', ['collection' => 'photos_before', 'label' => 'front', 'file' => UploadedFile::fake()->image('front.jpg')])->assertCreated();
        $this->postJson('/api/orders/'.$o->id.'/actions/submit')->assertOk();
    }

    public function test_saved_photo_can_be_reassigned_and_deleted_only_in_editable_stage(): void
    {
        $order = PurchaseOrder::where('status', 'draft')->where('category', 'stock')->firstOrFail();
        $id = $this->postJson('/api/orders/'.$order->id.'/media', ['collection' => 'photos_before', 'label' => 'front', 'file' => UploadedFile::fake()->image('front.jpg')])->assertCreated()->json('id');
        $this->patchJson('/media/'.$id, ['label' => 'back'])->assertNoContent();
        $this->assertSame('back', $order->fresh()->getMedia('photos_before')->firstWhere('id', $id)->getCustomProperty('label'));
        $this->patchJson('/media/'.$id, ['label' => 'invalid'])->assertUnprocessable();
        $order->update(['status' => 'accountant']);
        $this->patchJson('/media/'.$id, ['label' => 'front'])->assertUnprocessable();
        $this->deleteJson('/media/'.$id)->assertUnprocessable();
        $order->update(['status' => 'draft']);
        $this->deleteJson('/media/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('media', ['id' => $id]);
    }

    public function test_vehicle_video_upload_obeys_type_and_stage_constraints(): void
    {
        $order = PurchaseOrder::where('status', 'draft')->firstOrFail();
        $url = '/api/orders/'.$order->id.'/media';
        $this->postJson($url, ['collection' => 'vehicle_video', 'file' => UploadedFile::fake()->image('not-video.jpg')])->assertUnprocessable();
        $this->postJson($url, ['collection' => 'vehicle_video', 'file' => UploadedFile::fake()->create('car.mp4', 100, 'video/mp4')])->assertCreated();
        $order->update(['status' => 'accountant']);
        $this->postJson($url, ['collection' => 'vehicle_video', 'file' => UploadedFile::fake()->create('car.mp4', 100, 'video/mp4')])->assertUnprocessable();
    }

    public function test_submission_requires_quote_number_and_pdf_quote_document(): void
    {
        $o = PurchaseOrder::where('category', 'stock')->where('status', 'draft')->firstOrFail();
        $o->clearMediaCollection('quote');
        $o->update(['quote_number' => null]);

        $this->postJson('/api/orders/'.$o->id.'/actions/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $o->update(['quote_number' => 'QT-TEST-'.now()->format('YmdHis')]);
        $this->postJson('/api/orders/'.$o->id.'/actions/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->postJson('/api/orders/'.$o->id.'/media', [
            'collection' => 'quote',
            'file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $this->postJson('/api/orders/'.$o->id.'/actions/submit')->assertOk();
    }

    public function test_user_creation_roles_and_excel_success(): void
    {
        $this->postJson('/api/admin/users', ['name' => 'New employee', 'email' => 'new@example.test', 'password' => 'Secure-Test-2026', 'active' => true, 'all_branches' => false, 'roles' => ['employee'], 'branch_ids' => [1]])->assertCreated();
        $u = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($u->hasRole('employee'));
        $this->assertFalse($u->mayAccessBranch(2));
        $this->postJson('/api/admin/roles', ['name' => 'custom-reader', 'permissions' => ['orders.view']])->assertCreated();
        $file = UploadedFile::fake()->createWithContent('items.csv', "sku,name,unit,track_stock,unit_cost,minimum\nNEW-OK,New item,piece,1,12.50,2\n");
        $this->postJson('/api/excel/import/items', ['file' => $file])->assertOk()->assertJsonPath('rows', 1);
        $this->assertDatabaseHas('items', ['sku' => 'NEW-OK', 'unit_cost_minor' => 1250]);
    }

    public function test_cannot_approve_own_request_without_explicit_permission(): void
    {
        $u = User::where('email', 'accountant@rotana.test')->first();
        $o = PurchaseOrder::where('status', 'accountant')->first();
        $o->update(['created_by' => $u->id]);
        $this->actingAs($u);
        $this->postJson('/api/orders/'.$o->id.'/actions/approve')->assertUnprocessable();
    }

    public function test_draft_can_be_created_and_updated_via_validated_api(): void
    {
        $d = $this->validOrderPayload();
        $id = $this->postJson('/api/orders', $d)->assertCreated()->json('id');
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'total_minor' => 2302, 'notes' => 'ملاحظات عربية لاختبار الحفظ']);
        $d['lines'][0]['quantity'] = '3';
        $this->putJson('/api/orders/'.$id, $d)->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'total_minor' => 3453]);
        $d['lines'][] = $d['lines'][0];
        $this->putJson('/api/orders/'.$id, $d)->assertUnprocessable();
    }

    public function test_purchase_order_create_and_update_require_authentication_and_permission(): void
    {
        $payload = $this->validOrderPayload();
        auth()->logout();

        $this->postJson('/api/orders', $payload)->assertUnauthorized();
        $this->putJson('/api/orders/'.PurchaseOrder::where('status', 'draft')->firstOrFail()->id, $payload)->assertUnauthorized();

        $blocked = User::create(['name' => 'No PO permissions', 'email' => 'po-blocked@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);

        $this->postJson('/api/orders', $payload)->assertForbidden();
        $this->putJson('/api/orders/'.PurchaseOrder::where('status', 'draft')->firstOrFail()->id, $payload)->assertForbidden();
    }

    public function test_branch_limited_user_cannot_create_or_move_order_outside_authorized_branches(): void
    {
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());

        $this->postJson('/api/orders', $this->validOrderPayload(['branch_id' => 2, 'warehouse_id' => 2]))->assertForbidden();

        $order = PurchaseOrder::where('status', 'draft')->where('branch_id', 1)->firstOrFail();
        $this->putJson('/api/orders/'.$order->id, $this->validOrderPayload(['branch_id' => 2, 'warehouse_id' => 2]))->assertForbidden();
    }

    public function test_purchase_order_header_and_line_validation_uses_json_error_shape(): void
    {
        $response = $this->postJson('/api/orders', [
            'category' => 'invalid',
            'branch_id' => 99999,
            'cost_center_id' => 99999,
            'supplier_id' => 99999,
            'vehicle_id' => 99999,
            'warehouse_id' => 99999,
            'maintenance_card_id' => 99999,
            'date' => '07-09-2026',
            'priority' => 'later',
            'tax_percent' => '101',
            'lines' => [
                ['item_id' => 99999, 'quantity' => '0', 'unit_price' => '10.001'],
                'malformed',
            ],
        ])->assertUnprocessable();

        $response->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors([
                'category',
                'branch_id',
                'cost_center_id',
                'supplier_id',
                'vehicle_id',
                'warehouse_id',
                'maintenance_card_id',
                'date',
                'priority',
                'tax_percent',
                'lines.0.item_id',
                'lines.0.quantity',
                'lines.0.unit_price',
                'lines.1.item_id',
                'lines.1.quantity',
                'lines.1.unit_price',
            ]);
    }

    public function test_purchase_order_related_records_must_be_active_and_accessible_for_selected_category(): void
    {
        $inactiveSupplier = Supplier::firstOrFail();
        $inactiveSupplier->update(['active' => false]);

        $this->postJson('/api/orders', $this->validOrderPayload())->assertUnprocessable()->assertJsonValidationErrors(['order']);

        $inactiveSupplier->update(['active' => true]);
        Item::findOrFail(1)->update(['active' => false]);
        $this->postJson('/api/orders', $this->validOrderPayload())->assertUnprocessable()->assertJsonValidationErrors(['order']);

        Item::findOrFail(1)->update(['active' => true]);
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->postJson('/api/orders', $this->validOrderPayload([
            'category' => 'maintenance',
            'warehouse_id' => null,
            'vehicle_id' => Vehicle::where('branch_id', 2)->firstOrFail()->id,
        ]))->assertForbidden();
    }

    public function test_purchase_order_save_rejects_duplicate_items_and_client_total_manipulation(): void
    {
        $this->postJson('/api/orders', $this->validOrderPayload([
            'total_minor' => 1,
            'subtotal_minor' => 1,
            'tax_minor' => 1,
            'status' => 'closed',
        ]))->assertCreated();

        $order = PurchaseOrder::latest('id')->firstOrFail();
        $this->assertSame('draft', $order->status->value);
        $this->assertSame(2302, $order->total_minor);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $order->id, 'total_minor' => 1]);

        $this->postJson('/api/orders', $this->validOrderPayload([
            'lines' => [
                ['item_id' => 1, 'quantity' => '1', 'unit_price' => '10'],
                ['item_id' => 1, 'quantity' => '1', 'unit_price' => '10'],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['lines.0.item_id']);
    }

    public function test_failed_purchase_order_create_does_not_leave_partial_records(): void
    {
        $ordersBefore = PurchaseOrder::count();
        $linesBefore = OrderLine::count();

        $this->postJson('/api/orders', $this->validOrderPayload(['warehouse_id' => 2]))->assertUnprocessable();

        $this->assertSame($ordersBefore, PurchaseOrder::count());
        $this->assertSame($linesBefore, OrderLine::count());
    }

    public function test_purchase_order_update_replaces_lines_and_blocks_non_editable_status(): void
    {
        $id = $this->postJson('/api/orders', $this->validOrderPayload([
            'lines' => [
                ['item_id' => 1, 'quantity' => '2', 'unit_price' => '10.00'],
                ['item_id' => 3, 'quantity' => '1', 'unit_price' => '5.00'],
            ],
        ]))->assertCreated()->json('id');

        $this->putJson('/api/orders/'.$id, $this->validOrderPayload([
            'lines' => [
                ['item_id' => 1, 'quantity' => '3', 'unit_price' => '10.00'],
                ['item_id' => 4, 'quantity' => '2', 'unit_price' => '7.50'],
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('order_lines', ['purchase_order_id' => $id, 'item_id' => 1, 'quantity_milli' => 3000, 'total_minor' => 3000]);
        $this->assertDatabaseHas('order_lines', ['purchase_order_id' => $id, 'item_id' => 4, 'quantity_milli' => 2000, 'total_minor' => 1500]);
        $this->assertDatabaseMissing('order_lines', ['purchase_order_id' => $id, 'item_id' => 3]);

        PurchaseOrder::findOrFail($id)->update(['status' => 'accountant']);
        $this->putJson('/api/orders/'.$id, $this->validOrderPayload())->assertUnprocessable()->assertJsonValidationErrors(['order']);
    }

    public function test_vehicle_order_stores_its_own_odometer_without_requiring_a_maintenance_card(): void
    {
        $vehicle = Vehicle::findOrFail(1);
        $oldOdometer = $vehicle->odometer;
        $payload = $this->validOrderPayload(['category' => 'maintenance', 'vehicle_id' => 1, 'warehouse_id' => null, 'odometer' => $oldOdometer + 25]);
        $order = $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('maintenance_card_id', null)->assertJsonPath('odometer', $oldOdometer + 25)->json('id');
        $this->assertSame($oldOdometer, $vehicle->fresh()->odometer);
        $this->getJson('/api/orders/'.$order)->assertOk()->assertJsonPath('odometer', $oldOdometer + 25);
        unset($payload['odometer']);
        $this->putJson('/api/orders/'.$order, $payload)->assertOk()->assertJsonPath('odometer', $oldOdometer + 25);
        $this->postJson('/api/orders', $payload + ['odometer' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('odometer');

        $card = \App\Models\MaintenanceCard::create(['number' => 'CARD-ODO', 'vehicle_id' => 1, 'branch_id' => $vehicle->branch_id, 'created_by' => auth()->id(), 'date' => today(), 'type' => 'صيانة', 'status' => 'pending', 'odometer' => $oldOdometer - 50]);
        $this->postJson('/api/orders', array_replace($payload, ['maintenance_card_id' => $card->id]))->assertCreated()->assertJsonPath('odometer', $oldOdometer - 50);
        $this->postJson('/api/orders', $this->validOrderPayload(['category' => 'utilities', 'vehicle_id' => 1, 'warehouse_id' => 1, 'maintenance_card_id' => $card->id, 'odometer' => 123]))->assertCreated()->assertJsonPath('vehicle_id', null)->assertJsonPath('warehouse_id', null)->assertJsonPath('maintenance_card_id', null)->assertJsonPath('odometer', null);
    }

    public function test_advance_payment_keeps_approvals_and_allows_documents_after_payment_before_closure(): void
    {
        $id = $this->postJson('/api/orders', $this->validOrderPayload(['payment_timing' => 'before_receipt']))->assertCreated()->json('id');
        $url = '/api/orders/'.$id;
        $order = PurchaseOrder::findOrFail($id);
        $before = StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli');
        $this->postJson($url.'/media', ['collection' => 'quote', 'file' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')])->assertCreated();
        $this->postJson($url.'/actions/match')->assertUnprocessable();
        $this->postJson($url.'/actions/submit')->assertOk()->assertJsonPath('status', 'accountant');
        foreach (['manager', 'supervisor', 'matching'] as $status) {
            $this->postJson($url.'/actions/approve')->assertOk()->assertJsonPath('status', $status);
        }
        $this->postJson($url.'/actions/match')->assertOk()->assertJsonPath('status', 'ready');
        $this->assertDatabaseHas('approval_events', ['purchase_order_id' => $id, 'action' => 'orders.advance_payment_approved']);
        $this->assertNull($order->fresh()->receipt);
        $this->assertNull($order->fresh()->invoice);
        $order->supplier->update(['iban' => 'SA0380000000608010167519']);
        $this->get($url.'/transfer-pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $proof = $this->postJson($url.'/media', ['collection' => 'proof', 'file' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')])->assertCreated()->json('id');
        $payment = ['reference' => 'ADVANCE-PAYMENT', 'date' => today()->toDateString(), 'amount' => Amounts::money($order->total_minor), 'media_id' => $proof];
        $this->postJson($url.'/payment', array_replace($payment, ['amount' => '1']))->assertUnprocessable();
        $this->postJson($url.'/payment', $payment)->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson($url.'/payment', array_replace($payment, ['reference' => 'ADVANCE-DUPLICATE']))->assertUnprocessable();
        $this->assertSame($before, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->postJson($url.'/actions/close')->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('can_record_documents', true);
        $lines = $order->lines->map(fn ($line) => ['order_line_id' => $line->id, 'quantity' => Amounts::quantity($line->quantity_milli)])->all();
        $document = ['date' => today()->toDateString(), 'lines' => $lines];
        $this->postJson($url.'/receipt', $document)->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson($url.'/receipt', $document)->assertOk();
        $this->assertSame($before + 2000, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $invoice = $this->postJson($url.'/media', ['collection' => 'invoice', 'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')])->assertCreated()->json('id');
        $invoiceData = $document + ['number' => 'ADVANCE-INVOICE', 'total' => '1', 'media_id' => $invoice];
        $this->postJson($url.'/invoice', $invoiceData)->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson($url.'/actions/close')->assertUnprocessable();
        $invoiceData['total'] = Amounts::money($order->total_minor);
        $this->postJson($url.'/invoice', $invoiceData)->assertOk();
        $this->postJson($url.'/actions/close')->assertOk()->assertJsonPath('status', 'closed');
        $this->assertSame(1, $order->payment()->count());
        $this->postJson($url.'/receipt', $document)->assertUnprocessable();
        $this->postJson($url.'/invoice', $invoiceData)->assertUnprocessable();
    }

    public function test_service_expense_requires_supporting_document_and_invoice_but_no_vehicle_or_receipt(): void
    {
        $id = $this->postJson('/api/orders', $this->validOrderPayload(['category' => 'utilities', 'quote_number' => null, 'warehouse_id' => null]))->assertCreated()->json('id');
        $url = '/api/orders/'.$id;
        $this->postJson($url.'/actions/submit')->assertUnprocessable();
        $this->postJson($url.'/media', ['collection' => 'attachments', 'file' => UploadedFile::fake()->image('bill.jpg')])->assertCreated();
        $this->postJson($url.'/actions/submit')->assertOk();
        foreach (range(1, 3) as $stage) $this->postJson($url.'/actions/approve')->assertOk();
        $this->postJson($url.'/actions/match')->assertUnprocessable();
        $order = PurchaseOrder::findOrFail($id);
        $lines = $order->lines->map(fn ($line) => ['order_line_id' => $line->id, 'quantity' => Amounts::quantity($line->quantity_milli)])->all();
        $document = ['date' => today()->toDateString(), 'lines' => $lines];
        $this->postJson($url.'/receipt', $document)->assertUnprocessable();
        $invoice = $this->postJson($url.'/media', ['collection' => 'invoice', 'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')])->assertCreated()->json('id');
        $this->postJson($url.'/invoice', $document + ['number' => 'UTILITY-INVOICE', 'total' => Amounts::money($order->total_minor), 'media_id' => $invoice])->assertOk();
        $this->postJson($url.'/actions/match')->assertOk()->assertJsonPath('status', 'ready');
        $this->getJson($url)->assertOk()->assertJsonPath('requires_receipt', false)->assertJsonPath('matched', true);
        $this->assertNull($order->fresh()->receipt);
    }

    public function test_advance_payment_cannot_bypass_permission_scope_or_a_known_invoice_difference(): void
    {
        $this->postJson('/api/orders', $this->validOrderPayload(['payment_timing' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('payment_timing');
        $order = PurchaseOrder::where('status', 'matching')->whereHas('invoice', fn ($q) => $q->whereColumn('total_minor', '!=', 'purchase_orders.total_minor'))->firstOrFail();
        $order->update(['payment_timing' => 'before_receipt']);
        $this->postJson('/api/orders/'.$order->id.'/actions/match')->assertUnprocessable();
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->postJson('/api/orders/'.$order->id.'/actions/match')->assertForbidden();
        $order->update(['branch_id' => 2]);
        $this->postJson('/api/orders/'.$order->id.'/actions/match')->assertForbidden();
    }

    public function test_purchase_order_tax_is_limited_to_fifteen_percent_or_zero(): void
    {
        foreach (['5', '14.99', '16', '100'] as $tax) {
            $this->postJson('/api/orders', $this->validOrderPayload(['tax_percent' => $tax]))->assertUnprocessable()->assertJsonValidationErrors('tax_percent');
        }
        $response = $this->postJson('/api/orders', $this->validOrderPayload(['tax_percent' => '15.00', 'lines' => [['item_id' => 1, 'quantity' => '1.5', 'unit_price' => '10']]]))->assertCreated();
        $response->assertJsonPath('subtotal_minor', 1500)->assertJsonPath('tax_minor', 225)->assertJsonPath('total_minor', 1725);
        $this->putJson('/api/orders/'.$response->json('id'), $this->validOrderPayload(['tax_percent' => '5']))->assertUnprocessable();
        $this->assertDatabaseHas('purchase_orders', ['id' => $response->json('id'), 'tax_basis_points' => 1500, 'total_minor' => 1725]);
    }

    public function test_fractional_stock_order_receipt_invoice_matching_and_correction_keep_precision(): void
    {
        $order = $this->postJson('/api/orders', $this->validOrderPayload(['lines' => [['item_id' => 1, 'quantity' => '1.5', 'unit_price' => '10']]]))->assertCreated()->json();
        $id = $order['id'];
        $this->assertSame(1500, $order['lines'][0]['quantity_milli']);
        PurchaseOrder::findOrFail($id)->update(['status' => 'matching']);
        $before = StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli');
        $data = ['date' => today()->toDateString(), 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity' => '1.5']]];
        $this->postJson('/api/orders/'.$id.'/receipt', $data)->assertOk();
        $this->postJson('/api/orders/'.$id.'/receipt', $data)->assertOk();
        $this->assertSame($before + 1500, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $media = $this->postJson('/api/orders/'.$id.'/media', ['collection' => 'invoice', 'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')])->assertCreated()->json('id');
        $this->postJson('/api/orders/'.$id.'/invoice', $data + ['number' => 'FRACTION-INVOICE', 'total' => '17.25', 'media_id' => $media])->assertOk();
        $this->postJson('/api/orders/'.$id.'/actions/match')->assertOk()->assertJsonPath('status', 'ready');
        $data['lines'][0]['quantity'] = '1.25';
        $this->postJson('/api/orders/'.$id.'/receipt', $data)->assertOk()->assertJsonPath('status', 'matching');
        $this->assertSame($before + 1250, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->postJson('/api/orders/'.$id.'/actions/match')->assertUnprocessable();
    }

    public function test_fractional_inventory_movements_conserve_stock_and_reject_duplicate_or_excess_return(): void
    {
        $before = StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli');
        $data = $this->movement(['lines' => [['item_id' => 1, 'quantity' => '1.5']]]);
        $issue = $this->postJson('/api/inventory/movements', $data)->assertCreated()->json('id');
        $this->postJson('/api/inventory/movements', $data)->assertUnprocessable();
        $this->assertSame($before - 1500, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->postJson('/api/inventory/movements', $this->movement(['type' => 'return', 'source_movement_id' => $issue, 'lines' => [['item_id' => 1, 'quantity' => '1.25']]]))->assertCreated();
        $this->postJson('/api/inventory/movements', $this->movement(['type' => 'return', 'source_movement_id' => $issue, 'lines' => [['item_id' => 1, 'quantity' => '0.5']]]))->assertUnprocessable();
        $this->assertSame($before - 250, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $total = StockBalance::where('item_id', 1)->sum('quantity_milli');
        $this->postJson('/api/inventory/movements', $this->movement(['type' => 'transfer', 'destination_warehouse_id' => 2, 'lines' => [['item_id' => 1, 'quantity' => '0.125']]]))->assertCreated();
        $this->assertEquals($total, StockBalance::where('item_id', 1)->sum('quantity_milli'));
        $this->postJson('/api/inventory/movements', $this->movement(['type' => 'adjust', 'lines' => [['item_id' => 1, 'quantity' => '2.5']]]))->assertCreated();
        $this->assertSame(2500, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->postJson('/api/inventory/movements', $this->movement(['lines' => [['item_id' => 1, 'quantity' => '0.0001']]]))->assertUnprocessable();
    }

    public function test_ready_transfer_pdf_includes_bank_and_approvals_without_changing_order(): void
    {
        $order = PurchaseOrder::where('status', 'ready')->firstOrFail();
        $order->supplier->update(['iban' => 'SA0380000000608010167519']);
        $this->getJson('/api/orders/'.$order->id)->assertOk()->assertJsonPath('supplier.iban', 'SA0380000000608010167519');
        $before = $order->fresh()->toArray();
        $events = $order->approvals()->count();
        $response = $this->get('/api/orders/'.$order->id.'/transfer-pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $html = view('orders.transfer-pdf', ['order' => $order->fresh(['lines', 'invoice', 'supplier', 'creator', 'approvals.user']), 'generatedAt' => now()])->render();
        foreach ([$order->number, 'SA0380000000608010167519', 'طلب معتمد وجاهز للتحويل', 'اعتماد المدير', Amounts::money($order->total_minor)] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame($before, $order->fresh()->toArray());
        $this->assertSame($events, $order->approvals()->count());
        $this->assertNull($order->fresh()->payment);
    }

    public function test_transfer_pdf_blocks_missing_account_wrong_stage_mismatch_and_unauthorized_access(): void
    {
        $order = PurchaseOrder::where('status', 'ready')->firstOrFail();
        $url = '/api/orders/'.$order->id.'/transfer-pdf';
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('order');
        $order->supplier->update(['iban' => 'SA0380000000608010167519']);
        $order->invoice->update(['total_minor' => $order->total_minor + 1]);
        $this->getJson($url)->assertUnprocessable();
        $order->invoice->update(['total_minor' => $order->total_minor]);
        foreach (['draft', 'accountant', 'manager', 'supervisor', 'matching', 'paid', 'closed', 'rejected'] as $status) {
            $order->update(['status' => $status]);
            $this->getJson($url)->assertUnprocessable();
        }
        $order->update(['status' => 'ready', 'branch_id' => 2]);
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->getJson($url)->assertForbidden();
        $user = User::create(['name' => 'No PDF access', 'email' => 'no-pdf@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($user);
        $this->getJson($url)->assertForbidden();
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_purchase_order_accepts_zero_nullable_and_optional_values_allowed_by_rules(): void
    {
        $id = $this->postJson('/api/orders', $this->validOrderPayload([
            'category' => 'utilities',
            'warehouse_id' => null,
            'vehicle_id' => null,
            'maintenance_card_id' => null,
            'quote_number' => null,
            'notes' => null,
            'tax_percent' => '0',
            'lines' => [
                ['item_id' => 5, 'quantity' => '1.250', 'unit_price' => '0'],
            ],
        ]))->assertCreated()->json('id');

        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'tax_basis_points' => 0, 'total_minor' => 0, 'vehicle_id' => null, 'warehouse_id' => null, 'quote_number' => null, 'notes' => null]);
        $this->assertDatabaseHas('order_lines', ['purchase_order_id' => $id, 'item_id' => 5, 'quantity_milli' => 1250, 'unit_price_minor' => 0, 'total_minor' => 0]);
    }
}
