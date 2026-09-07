<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterDataListingTest extends TestCase
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

    private function masterParams(string $kind, array $extra = []): array
    {
        $columns = match ($kind) {
            'vehicles' => ['id', 'plate', 'model', 'branch.name', 'year', 'odometer', 'active'],
            'suppliers' => ['id', 'name', 'code', 'phone', 'active'],
            'items' => ['id', 'name', 'sku', 'unit', 'active'],
            'regions' => ['id', 'name', 'active'],
            'branches' => ['id', 'name', 'code', 'active'],
            'cost-centers' => ['id', 'name', 'code', 'active'],
            'warehouses' => ['id', 'name', 'code', 'branch.name', 'active'],
            default => ['id', 'name', 'active'],
        };

        return array_replace_recursive([
            'draw' => 44,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 1, 'dir' => 'asc']],
            'columns' => array_map(fn ($column) => [
                'data' => $column,
                'name' => $column,
                'searchable' => in_array($column, ['branch.name'], true) ? 'false' : 'true',
                'orderable' => in_array($column, ['branch.name'], true) ? 'false' : 'true',
                'search' => ['value' => '', 'regex' => 'false'],
            ], $columns),
        ], $extra);
    }

    private function masterDataTable(string $kind, array $params = [])
    {
        return $this->getJson('/api/masters/'.$kind.'?'.http_build_query($this->masterParams($kind, $params)));
    }

    public function test_master_listings_guard_authentication_permissions_and_shape(): void
    {
        auth()->logout();
        $this->masterDataTable('suppliers')->assertUnauthorized();

        $blocked = User::create(['name' => 'No master list access', 'email' => 'no-master-list@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        foreach (['suppliers', 'items', 'vehicles', 'regions', 'branches', 'cost-centers', 'warehouses'] as $kind) {
            $this->masterDataTable($kind)->assertForbidden();
        }

        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
        foreach (['suppliers', 'items', 'vehicles', 'regions', 'branches', 'cost-centers', 'warehouses'] as $kind) {
            $this->masterDataTable($kind)
                ->assertOk()
                ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
        }
    }

    public function test_master_listings_search_sort_empty_and_active_state(): void
    {
        Supplier::create(['name' => 'مورد اختبار الجداول', 'code' => 'SUP-TABLE-1', 'phone' => '+966500000001', 'active' => false]);
        Item::create(['name' => 'صنف اختبار الجداول', 'sku' => 'ITEM-TABLE-1', 'unit' => 'قطعة', 'track_stock' => true, 'unit_cost_minor' => 12345, 'minimum_milli' => 2000, 'active' => false]);
        Region::create(['name' => 'منطقة اختبار الجداول', 'active' => false]);
        CostCenter::create(['name' => 'مركز اختبار الجداول', 'code' => 'CC-TABLE-1', 'active' => false]);

        foreach ([
            'suppliers' => 'SUP-TABLE-1',
            'items' => 'ITEM-TABLE-1',
            'regions' => 'منطقة اختبار الجداول',
            'cost-centers' => 'CC-TABLE-1',
        ] as $kind => $needle) {
            $response = $this->masterDataTable($kind, ['search' => ['value' => $needle, 'regex' => 'false']])->assertOk()->json();
            $this->assertSame(1, $response['recordsFiltered']);
            $this->assertIsBool($response['data'][0]['active']);
            $this->assertArrayNotHasKey('password', $response['data'][0]);

            $empty = $this->masterDataTable($kind, ['search' => ['value' => 'NO-SUCH-MASTER-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
            $this->assertSame([], $empty['data']);

            $this->masterDataTable($kind, ['search' => ['value' => str_repeat('%_', 120), 'regex' => 'false']])
                ->assertOk()
                ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
        }
    }

    public function test_branch_scoped_master_listings_do_not_leak_through_scope_or_explicit_branch_filter(): void
    {
        $role = Role::create(['name' => 'limited-master-listing', 'guard_name' => 'web']);
        $role->syncPermissions(['masters.manage', 'vehicles.view']);
        $user = User::create(['name' => 'Limited master user', 'email' => 'limited-master@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => false]);
        $user->assignRole($role);
        $user->branches()->sync([1]);

        $localBranch = Branch::findOrFail(1);
        $foreignBranch = Branch::findOrFail(2);
        $warehouse = Warehouse::create(['name' => 'مخزن محلي للاختبار', 'code' => 'WH-LOCAL-TABLE', 'branch_id' => $localBranch->id, 'active' => false]);
        $foreignWarehouse = Warehouse::create(['name' => 'مخزن أجنبي للاختبار', 'code' => 'WH-FOREIGN-TABLE', 'branch_id' => $foreignBranch->id, 'active' => true]);
        $vehicle = Vehicle::create(['plate' => 'MD-LOCAL-123', 'plate_key' => 'MDLOCAL123', 'vin' => 'MDLOCAL123VIN', 'model' => 'اختبار', 'year' => 2026, 'color' => 'أبيض', 'odometer' => 111, 'branch_id' => $localBranch->id, 'cost_center_id' => CostCenter::firstOrFail()->id, 'active' => false]);
        $foreignVehicle = Vehicle::create(['plate' => 'MD-FOREIGN-456', 'plate_key' => 'MDFOREIGN456', 'vin' => 'MDFOREIGN456VIN', 'model' => 'اختبار', 'year' => 2026, 'color' => 'أبيض', 'odometer' => 222, 'branch_id' => $foreignBranch->id, 'cost_center_id' => CostCenter::firstOrFail()->id, 'active' => true]);

        $this->actingAs($user);
        $warehouses = $this->masterDataTable('warehouses', ['search' => ['value' => 'WH-', 'regex' => 'false'], 'length' => 100])->assertOk()->json();
        $this->assertContains($warehouse->code, collect($warehouses['data'])->pluck('code')->all());
        $this->assertNotContains($foreignWarehouse->code, collect($warehouses['data'])->pluck('code')->all());
        $this->assertIsBool(collect($warehouses['data'])->firstWhere('code', $warehouse->code)['active']);

        $vehicles = $this->masterDataTable('vehicles', ['search' => ['value' => 'MD-', 'regex' => 'false'], 'length' => 100])->assertOk()->json();
        $this->assertContains($vehicle->plate, collect($vehicles['data'])->pluck('plate')->all());
        $this->assertNotContains($foreignVehicle->plate, collect($vehicles['data'])->pluck('plate')->all());
        $this->assertIsInt(collect($vehicles['data'])->firstWhere('plate', $vehicle->plate)['odometer']);

        $branches = $this->masterDataTable('branches', ['search' => ['value' => $foreignBranch->name, 'regex' => 'false'], 'length' => 100])->assertOk()->json();
        $this->assertSame([], $branches['data']);

        $this->getJson('/api/masters/warehouses?'.http_build_query($this->masterParams('warehouses', ['branch_id' => 2])))->assertForbidden();
        $this->getJson('/api/masters/vehicles?'.http_build_query($this->masterParams('vehicles', ['branch_id' => 2])))->assertForbidden();
    }

    public function test_master_listings_cap_server_side_page_length(): void
    {
        for ($i = 0; $i < 105; $i++) {
            Supplier::create(['name' => 'مورد حد الصفحة '.$i, 'code' => 'SUP-CAP-'.$i, 'active' => true]);
        }

        $response = $this->masterDataTable('suppliers', ['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($response['data']));
    }
}
