<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
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
        $this->seed(DatabaseSeeder::class);
    }

    public function test_guest_users_cannot_access_dashboard_endpoint(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_authorized_active_user_can_access_dashboard_endpoint(): void
    {
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail())
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'counts',
                'category_counts',
                'totals',
                'order_total_minor',
                'paid_minor',
                'low_stock',
                'vehicles',
                'cards',
                'recent',
            ]);
    }

    public function test_user_without_dashboard_permission_receives_forbidden_response(): void
    {
        $user = User::factory()->create([
            'active' => true,
            'all_branches' => true,
        ]);
        $role = Role::create(['name' => 'orders-only-dashboard-test', 'guard_name' => 'web']);
        $role->givePermissionTo('orders.view');
        $user->assignRole($role);

        $this->actingAs($user)
            ->getJson('/api/dashboard')
            ->assertForbidden();
    }

    public function test_branch_limited_user_sees_only_permitted_branch_dashboard_data(): void
    {
        $branch = Branch::where('code', 'B2')->firstOrFail();
        $otherBranch = Branch::where('code', 'B3')->firstOrFail();
        $user = $this->dashboardUser([$branch->id]);

        $visibleOrder = $this->orderForBranch($branch->id, [
            'number' => 'DASH-B2-1',
            'status' => 'draft',
            'category' => 'stock',
            'total_minor' => 12345,
        ]);
        $rejectedVisibleOrder = $this->orderForBranch($branch->id, [
            'number' => 'DASH-B2-2',
            'status' => 'rejected',
            'category' => 'maintenance',
            'total_minor' => 1005,
        ]);
        $hiddenOrder = $this->orderForBranch($otherBranch->id, [
            'number' => 'DASH-B3-1',
            'status' => 'paid',
            'category' => 'stock',
            'total_minor' => 99999,
        ]);

        Payment::create([
            'purchase_order_id' => $visibleOrder->id,
            'reference' => 'DASH-PAY-VISIBLE',
            'date' => today()->toDateString(),
            'amount_minor' => 6789,
            'media_id' => $this->unusedMediaId(),
            'created_by' => $user->id,
        ]);
        Payment::create([
            'purchase_order_id' => $hiddenOrder->id,
            'reference' => 'DASH-PAY-HIDDEN',
            'date' => today()->toDateString(),
            'amount_minor' => 50000,
            'media_id' => $this->unusedMediaId(),
            'created_by' => $user->id,
        ]);

        Vehicle::where('branch_id', $branch->id)->update(['active' => false]);
        Vehicle::where('branch_id', $otherBranch->id)->update(['active' => false]);
        Vehicle::create([
            'plate' => 'اختبار ٢',
            'plate_key' => 'DASHB2',
            'model' => 'Dashboard Branch 2',
            'year' => 2024,
            'color' => 'White',
            'odometer' => 100,
            'branch_id' => $branch->id,
            'cost_center_id' => 1,
            'active' => true,
        ]);
        Vehicle::create([
            'plate' => 'اختبار ٣',
            'plate_key' => 'DASHB3',
            'model' => 'Dashboard Branch 3',
            'year' => 2024,
            'color' => 'White',
            'odometer' => 100,
            'branch_id' => $otherBranch->id,
            'cost_center_id' => 1,
            'active' => true,
        ]);

        MaintenanceCard::whereIn('branch_id', [$branch->id, $otherBranch->id])->delete();
        MaintenanceCard::create([
            'number' => 'MC-DASH-B2-1',
            'vehicle_id' => Vehicle::where('branch_id', $branch->id)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة',
            'status' => 'pending',
            'odometer' => 100,
        ]);
        MaintenanceCard::create([
            'number' => 'MC-DASH-B2-CLOSED',
            'vehicle_id' => Vehicle::where('branch_id', $branch->id)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'created_by' => $user->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة',
            'status' => 'closed',
            'odometer' => 100,
        ]);
        MaintenanceCard::create([
            'number' => 'MC-DASH-B3-1',
            'vehicle_id' => Vehicle::where('branch_id', $otherBranch->id)->firstOrFail()->id,
            'branch_id' => $otherBranch->id,
            'created_by' => $user->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة',
            'status' => 'pending',
            'odometer' => 100,
        ]);

        $this->makeLowStock($branch->id, 1000, 5000);
        $this->makeLowStock($otherBranch->id, 0, 5000);

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame(1, $response['counts']['draft']);
        $this->assertSame(1, $response['counts']['rejected']);
        $this->assertArrayNotHasKey('paid', $response['counts']);
        $this->assertSame(['maintenance' => 1, 'stock' => 1], collect($response['category_counts'])->sortKeys()->all());
        $filtered = $this->getJson('/api/orders?category=stock')->assertOk()->json('data');
        $this->assertSame([$visibleOrder->id], array_column($filtered, 'id'));
        $this->assertSame(13350, $response['order_total_minor']);
        $this->assertSame(6789, $response['paid_minor']);
        $this->assertSame(1, $response['vehicles']);
        $this->assertSame(1, $response['cards']);
        $this->assertSame(1, $response['low_stock']);
        $this->assertSame([$rejectedVisibleOrder->id, $visibleOrder->id], array_column($response['recent'], 'id'));
        $this->assertNotContains($hiddenOrder->id, array_column($response['recent'], 'id'));
    }

    public function test_global_user_sees_broader_dashboard_scope(): void
    {
        $branch = Branch::where('code', 'B2')->firstOrFail();
        $otherBranch = Branch::where('code', 'B3')->firstOrFail();
        $admin = User::where('email', 'admin@rotana.test')->firstOrFail();

        $first = $this->orderForBranch($branch->id, [
            'number' => 'DASH-GLOBAL-1',
            'status' => 'draft',
            'category' => 'stock',
            'total_minor' => 2000,
        ]);
        $second = $this->orderForBranch($otherBranch->id, [
            'number' => 'DASH-GLOBAL-2',
            'status' => 'paid',
            'category' => 'stock',
            'total_minor' => 3000,
        ]);
        Payment::create([
            'purchase_order_id' => $second->id,
            'reference' => 'DASH-GLOBAL-PAY',
            'date' => today()->toDateString(),
            'amount_minor' => 3000,
            'media_id' => $this->unusedMediaId(),
            'created_by' => $admin->id,
        ]);
        $this->makeLowStock($branch->id, 1000, 5000);
        $this->makeLowStock($otherBranch->id, 1000, 5000);

        $response = $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()->json();

        $this->assertGreaterThanOrEqual(2, $response['counts']['draft']);
        $this->assertGreaterThanOrEqual(2, $response['counts']['paid']);
        $this->assertContains($first->id, array_column($response['recent'], 'id'));
        $this->assertContains($second->id, array_column($response['recent'], 'id'));
        $this->assertGreaterThanOrEqual(5000, $response['order_total_minor']);
        $this->assertGreaterThanOrEqual(3000, $response['paid_minor']);
        $this->assertGreaterThanOrEqual(2, $response['low_stock']);
    }

    public function test_empty_dashboard_scope_returns_zero_values_and_empty_recent_collection(): void
    {
        $user = $this->dashboardUser([]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'counts',
                'category_counts',
                'totals',
                'order_total_minor',
                'paid_minor',
                'low_stock',
                'vehicles',
                'cards',
                'recent',
            ])
            ->json();

        $this->assertSame([], $response['counts']);
        $this->assertSame([], $response['totals']);
        $this->assertSame([], $response['category_counts']);
        $this->assertSame(0, $response['order_total_minor']);
        $this->assertSame(0, $response['paid_minor']);
        $this->assertSame(0, $response['low_stock']);
        $this->assertSame(0, $response['vehicles']);
        $this->assertSame(0, $response['cards']);
        $this->assertSame([], $response['recent']);
        foreach (['order_total_minor', 'paid_minor', 'low_stock', 'vehicles', 'cards'] as $key) {
            $this->assertIsInt($response[$key]);
        }
    }

    public function test_recent_orders_use_latest_id_order_and_existing_limit(): void
    {
        $branch = Branch::where('code', 'B2')->firstOrFail();
        $user = $this->dashboardUser([$branch->id]);

        for ($i = 1; $i <= 9; $i++) {
            $this->orderForBranch($branch->id, [
                'number' => 'DASH-RECENT-'.$i,
                'status' => 'draft',
                'category' => 'stock',
                'total_minor' => $i * 100,
            ]);
        }

        $recent = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json('recent');

        $this->assertCount(8, $recent);
        $this->assertSame(
            range(PurchaseOrder::max('id'), PurchaseOrder::max('id') - 7),
            array_column($recent, 'id')
        );
    }

    public function test_dashboard_recent_tabs_respect_permissions_and_branch_scope(): void
    {
        $branch = Branch::where('code', 'B2')->firstOrFail();
        $otherBranch = Branch::where('code', 'B3')->firstOrFail();
        $user = $this->dashboardUser([$branch->id]);
        $visible = $this->orderForBranch($branch->id, ['number' => 'DASH-TAB-VISIBLE']);
        $hidden = $this->orderForBranch($otherBranch->id, ['number' => 'DASH-TAB-HIDDEN']);

        $this->actingAs($user)->getJson('/api/dashboard/recent/orders')
            ->assertOk()
            ->assertJsonFragment(['number' => $visible->number])
            ->assertJsonMissing(['number' => $hidden->number]);

        $restricted = User::factory()->create(['active' => true, 'all_branches' => true]);
        $role = Role::create(['name' => 'dashboard-orders-tabs-test', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard.view', 'orders.view']);
        $restricted->assignRole($role);

        $this->actingAs($restricted)->getJson('/api/dashboard/recent/cards')->assertForbidden();
    }

    private function dashboardUser(array $branchIds): User
    {
        $user = User::factory()->create([
            'active' => true,
            'all_branches' => false,
        ]);
        $user->assignRole('employee');
        $user->branches()->sync($branchIds);

        return $user;
    }

    private function orderForBranch(int $branchId, array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_replace([
            'number' => 'DASH-ORDER',
            'category' => 'stock',
            'branch_id' => $branchId,
            'cost_center_id' => 1,
            'supplier_id' => 1,
            'warehouse_id' => Warehouse::where('branch_id', $branchId)->firstOrFail()->id,
            'date' => today()->toDateString(),
            'priority' => 'normal',
            'status' => 'draft',
            'branch_name' => Branch::findOrFail($branchId)->name,
            'region_name' => 'اختبار',
            'supplier_name' => 'مورد اختبار',
            'subtotal_minor' => 0,
            'tax_basis_points' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'created_by' => User::where('email', 'admin@rotana.test')->firstOrFail()->id,
        ], $attributes));
    }

    private function makeLowStock(int $branchId, int $quantityMilli, int $minimumMilli): StockBalance
    {
        $item = Item::create([
            'sku' => 'DASH-LOW-'.$branchId.'-'.$quantityMilli.'-'.$minimumMilli,
            'name' => 'Dashboard Low Stock '.$branchId,
            'unit' => 'قطعة',
            'track_stock' => true,
            'unit_cost_minor' => 100,
            'minimum_milli' => $minimumMilli,
            'active' => true,
        ]);

        return StockBalance::create([
            'warehouse_id' => Warehouse::where('branch_id', $branchId)->firstOrFail()->id,
            'item_id' => $item->id,
            'quantity_milli' => $quantityMilli,
        ]);
    }

    private function unusedMediaId(): int
    {
        return Media::whereNotIn('id', Payment::pluck('media_id')->filter())->value('id');
    }
}
