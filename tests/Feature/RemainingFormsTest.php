<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemainingFormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Session::start();
        $this->withSession(['_token' => csrf_token()]);
        $this->withHeader('X-CSRF-TOKEN', csrf_token());
        config(['rotana.demo_password' => 'Testing-Rotana-2026']);
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
    }

    private function movement(array $extra = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'type' => 'issue',
            'warehouse_id' => 1,
            'vehicle_id' => 1,
            'odometer' => 45101,
            'date' => today()->toDateString(),
            'notes' => 'اختبار حركة مخزنية',
            'lines' => [['item_id' => 1, 'quantity' => 1]],
        ], $extra);
    }

    public function test_maintenance_card_form_validates_permissions_branch_active_vehicle_and_status_order(): void
    {
        auth()->logout();
        $this->postJson('/api/cards', [])->assertUnauthorized();

        $blocked = User::create(['name' => 'No card form access', 'email' => 'no-card-form@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->postJson('/api/cards', [])->assertForbidden();

        $this->actingAs(User::where('email', 'maintenance@rotana.test')->firstOrFail());
        $foreignVehicle = Vehicle::where('branch_id', 2)->firstOrFail();
        $this->postJson('/api/cards', [
            'vehicle_id' => $foreignVehicle->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة دورية',
            'odometer' => $foreignVehicle->odometer,
        ])->assertForbidden();

        $vehicle = Vehicle::where('branch_id', 1)->firstOrFail();
        $vehicle->update(['active' => false]);
        $this->postJson('/api/cards', [
            'vehicle_id' => $vehicle->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة دورية',
            'odometer' => $vehicle->odometer,
        ])->assertUnprocessable();

        $vehicle->update(['active' => true, 'odometer' => 1000]);
        $response = $this->postJson('/api/cards', [
            'vehicle_id' => $vehicle->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة دورية',
            'odometer' => 1200,
            'branch_id' => 2,
            'status' => 'closed',
        ])->assertCreated();
        $this->assertSame(1, $response['branch_id']);
        $this->assertSame('pending', $response['status']);
        $this->assertSame(1200, $vehicle->fresh()->odometer);

        $card = MaintenanceCard::findOrFail($response['id']);
        $this->patchJson('/api/cards/'.$card->id, ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson('/api/cards/'.$card->id, ['status' => 'waiting_parts', 'notes' => 'تم الفحص'])->assertOk();
    }

    public function test_master_forms_validate_json_shape_scope_mass_assignment_and_numeric_conversion(): void
    {
        $this->postJson('/api/masters/items', [
            'name' => '',
            'sku' => '',
            'unit' => '',
            'track_stock' => true,
            'unit_cost' => '12.345',
            'minimum' => '1.5',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'sku', 'unit', 'minimum']);

        $item = $this->postJson('/api/masters/items', [
            'name' => 'صنف نموذج المتبقي',
            'sku' => 'FORM-ITEM-1',
            'unit' => 'قطعة',
            'track_stock' => true,
            'unit_cost' => '12.35',
            'minimum' => '2',
            'unit_cost_minor' => 1,
            'created_by' => 999,
        ])->assertCreated();
        $this->assertSame(1235, $item['unit_cost_minor']);
        $this->assertArrayNotHasKey('created_by', $item->json());

        $vehicle = Vehicle::where('branch_id', 1)->firstOrFail();
        $this->putJson('/api/masters/vehicles/'.$vehicle->id, [
            'plate' => $vehicle->plate,
            'vin' => $vehicle->vin,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'odometer' => max(0, $vehicle->odometer - 1),
            'branch_id' => $vehicle->branch_id,
            'cost_center_id' => $vehicle->cost_center_id,
            'active' => true,
        ])->assertUnprocessable();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->postJson('/api/masters/vehicles', [
            'plate' => 'FORM-VH-2',
            'vin' => 'FORMVH2VIN',
            'model' => 'نموذج',
            'year' => 2026,
            'color' => 'أبيض',
            'odometer' => 1,
            'branch_id' => 2,
            'cost_center_id' => CostCenter::firstOrFail()->id,
            'active' => true,
        ])->assertForbidden();
    }

    public function test_inventory_movement_forms_validate_access_active_records_transaction_and_duplicate_request_key(): void
    {
        auth()->logout();
        $this->postJson('/api/inventory/movements', $this->movement())->assertUnauthorized();

        $this->actingAs(User::where('email', 'warehouse@rotana.test')->firstOrFail());
        Warehouse::findOrFail(1)->update(['active' => false]);
        $this->postJson('/api/inventory/movements', $this->movement())->assertUnprocessable()->assertJsonValidationErrors(['inventory']);
        Warehouse::findOrFail(1)->update(['active' => true]);

        Vehicle::findOrFail(1)->update(['active' => false]);
        $this->postJson('/api/inventory/movements', $this->movement())->assertUnprocessable()->assertJsonValidationErrors(['inventory']);
        Vehicle::findOrFail(1)->update(['active' => true]);

        Item::findOrFail(1)->update(['active' => false]);
        $this->postJson('/api/inventory/movements', $this->movement())->assertUnprocessable()->assertJsonValidationErrors(['inventory']);
        Item::findOrFail(1)->update(['active' => true]);

        $beforeBalances = StockBalance::pluck('quantity_milli', 'id')->all();
        $beforeMovements = StockMovement::count();
        $this->postJson('/api/inventory/movements', $this->movement([
            'lines' => [['item_id' => 1, 'quantity' => 1], ['item_id' => 2, 'quantity' => 999999]],
        ]))->assertUnprocessable();
        $this->assertSame($beforeBalances, StockBalance::pluck('quantity_milli', 'id')->all());
        $this->assertSame($beforeMovements, StockMovement::count());

        $payload = $this->movement();
        $this->postJson('/api/inventory/movements', $payload)->assertCreated();
        $this->postJson('/api/inventory/movements', $payload)->assertUnprocessable()->assertJsonValidationErrors(['inventory']);
    }

    public function test_inventory_movement_can_save_multiple_lines_and_updates_each_balance(): void
    {
        $before = StockBalance::where('warehouse_id', 1)->pluck('quantity_milli', 'item_id');

        $movement = $this->postJson('/api/inventory/movements', $this->movement([
            'lines' => [
                ['item_id' => 1, 'quantity' => 1],
                ['item_id' => 2, 'quantity' => 2],
            ],
        ]))->assertCreated()->json();

        $this->assertCount(2, $movement['lines']);
        $this->assertSame($before[1] - 1000, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->assertSame($before[2] - 2000, StockBalance::where('warehouse_id', 1)->where('item_id', 2)->value('quantity_milli'));
    }

    public function test_user_and_role_forms_validate_permissions_json_shape_and_protections(): void
    {
        auth()->logout();
        $this->postJson('/api/admin/users', [])->assertUnauthorized();
        $this->postJson('/api/admin/roles', [])->assertUnauthorized();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->postJson('/api/admin/users', [])->assertForbidden();
        $this->postJson('/api/admin/roles', [])->assertForbidden();

        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
        $this->postJson('/api/admin/users', [
            'name' => '',
            'email' => 'invalid',
            'password' => 'short',
            'active' => true,
            'all_branches' => false,
            'roles' => [],
            'branch_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'roles']);

        $user = $this->postJson('/api/admin/users', [
            'name' => 'مستخدم نموذج المتبقي',
            'email' => 'remaining-form-user@example.test',
            'password' => 'Secure-Test-2026',
            'active' => true,
            'all_branches' => false,
            'roles' => ['employee'],
            'branch_ids' => [1],
            'password_hash' => 'client-controlled',
        ])->assertCreated();
        $this->assertArrayNotHasKey('password', $user->json());
        $this->assertTrue(User::where('email', 'remaining-form-user@example.test')->firstOrFail()->hasRole('employee'));

        $this->postJson('/api/admin/roles', [
            'name' => 'remaining-custom-role',
            'permissions' => ['orders.view', 'not-real'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permissions.1']);

        $this->postJson('/api/admin/roles', [
            'name' => 'remaining-custom-role',
            'permissions' => ['orders.view', 'cards.view'],
        ])->assertCreated();

        $admin = User::where('email', 'admin@rotana.test')->firstOrFail();
        $this->putJson('/api/admin/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'active' => true,
            'all_branches' => false,
            'roles' => ['admin'],
            'branch_ids' => [1],
        ])->assertUnprocessable();
    }
}
