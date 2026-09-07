<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\PurchaseOrder;
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

class GlobalSearchTest extends TestCase
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

    private function search(string $term)
    {
        return $this->getJson('/api/search?q='.urlencode($term));
    }

    private function vehicle(int $branchId, string $plate): Vehicle
    {
        return Vehicle::create([
            'plate' => $plate,
            'plate_key' => str_replace(['-', ' '], '', $plate),
            'vin' => str_replace(['-', ' '], '', $plate).'VIN',
            'model' => 'نموذج بحث',
            'year' => 2026,
            'color' => 'أبيض',
            'odometer' => 100,
            'branch_id' => $branchId,
            'cost_center_id' => CostCenter::firstOrFail()->id,
            'active' => true,
        ]);
    }

    private function order(int $branchId, string $number, string $supplierName = 'مورد البحث'): PurchaseOrder
    {
        $branch = Branch::findOrFail($branchId);

        return PurchaseOrder::create([
            'number' => $number,
            'category' => 'stock',
            'status' => 'draft',
            'branch_id' => $branchId,
            'cost_center_id' => CostCenter::firstOrFail()->id,
            'supplier_id' => Supplier::firstOrFail()->id,
            'warehouse_id' => Warehouse::where('branch_id', $branchId)->firstOrFail()->id,
            'created_by' => User::where('email', 'admin@rotana.test')->firstOrFail()->id,
            'date' => today()->toDateString(),
            'priority' => 'normal',
            'branch_name' => $branch->name,
            'region_name' => $branch->region?->name ?? 'منطقة',
            'supplier_name' => $supplierName,
            'subtotal_minor' => 1000,
            'tax_basis_points' => 1500,
            'tax_minor' => 150,
            'total_minor' => 1150,
        ]);
    }

    private function card(Vehicle $vehicle, string $number): MaintenanceCard
    {
        return MaintenanceCard::create([
            'number' => $number,
            'vehicle_id' => $vehicle->id,
            'branch_id' => $vehicle->branch_id,
            'created_by' => User::where('email', 'admin@rotana.test')->firstOrFail()->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة بحث',
            'odometer' => $vehicle->odometer,
            'status' => 'pending',
        ]);
    }

    public function test_global_search_requires_authentication_and_query_length(): void
    {
        auth()->logout();
        $this->search('PO')->assertUnauthorized();

        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
        $this->search('P')->assertUnprocessable()->assertJsonValidationErrors(['q']);
        $this->search(str_repeat('A', 81))->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    public function test_global_search_is_permission_aware_and_returns_authorized_urls_only(): void
    {
        $vehicle = $this->vehicle(1, 'GS-PERM-1');
        $this->order(1, 'GS-PERM-ORDER');
        $this->card($vehicle, 'GS-PERM-CARD');
        Supplier::create(['name' => 'مورد GS-PERM', 'code' => 'GS-PERM-SUP', 'phone' => '5551000', 'active' => true]);
        Item::create(['name' => 'صنف GS-PERM', 'sku' => 'GS-PERM-ITEM', 'unit' => 'قطعة', 'track_stock' => true, 'unit_cost_minor' => 100, 'minimum_milli' => 1000, 'active' => true]);

        $role = Role::create(['name' => 'vehicle-search-only', 'guard_name' => 'web']);
        $role->syncPermissions(['vehicles.view']);
        $user = User::create(['name' => 'Vehicle search only', 'email' => 'vehicle-search-only@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => false]);
        $user->assignRole($role);
        $user->branches()->sync([1]);

        $this->actingAs($user);
        $results = $this->search('GS-PERM')->assertOk()->json('results');
        $this->assertSame(['vehicles'], array_values(array_unique(array_column($results, 'type'))));
        $this->assertSame('#master/vehicles/'.$vehicle->id, $results[0]['url']);
        $this->assertEqualsCanonicalizing(['type', 'label', 'secondary', 'url', 'icon'], array_keys($results[0]));
    }

    public function test_global_search_respects_branch_scope_and_supports_arabic_codes_and_plates(): void
    {
        $localVehicle = $this->vehicle(1, 'GS-LOCAL-123');
        $foreignVehicle = $this->vehicle(2, 'GS-FOREIGN-456');
        $localOrder = $this->order(1, 'GS-LOCAL-ORDER', 'مورد عربي للبحث');
        $foreignOrder = $this->order(2, 'GS-FOREIGN-ORDER', 'مورد عربي للبحث');
        $localCard = $this->card($localVehicle, 'GS-LOCAL-CARD');
        $foreignCard = $this->card($foreignVehicle, 'GS-FOREIGN-CARD');

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $results = $this->search('GS-')->assertOk()->json('results');
        $labels = collect($results)->pluck('label')->all();

        $this->assertContains($localVehicle->plate, $labels);
        $this->assertContains($localOrder->number, $labels);
        $this->assertContains($localCard->number, $labels);
        $this->assertNotContains($foreignVehicle->plate, $labels);
        $this->assertNotContains($foreignOrder->number, $labels);
        $this->assertNotContains($foreignCard->number, $labels);

        $arabic = $this->search('عربي')->assertOk()->json('results');
        $this->assertContains($localOrder->number, collect($arabic)->pluck('label')->all());
    }

    public function test_global_search_limits_results_and_escapes_wildcards(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $vehicle = $this->vehicle(1, 'GS-LIMIT-VH-'.$i);
            $this->card($vehicle, 'GS-LIMIT-MC-'.$i);
            $this->order(1, 'GS-LIMIT-PO-'.$i);
            Supplier::create(['name' => 'مورد GS-LIMIT '.$i, 'code' => 'GS-LIMIT-SUP-'.$i, 'phone' => '5552'.$i, 'active' => true]);
            Item::create(['name' => 'صنف GS-LIMIT '.$i, 'sku' => 'GS-LIMIT-ITEM-'.$i, 'unit' => 'قطعة', 'track_stock' => true, 'unit_cost_minor' => 100, 'minimum_milli' => 1000, 'active' => true]);
        }

        $results = $this->search('GS-LIMIT')->assertOk()->json('results');
        $this->assertLessThanOrEqual(15, count($results));
        foreach (collect($results)->groupBy('type') as $rows) {
            $this->assertLessThanOrEqual(5, $rows->count());
        }

        Supplier::create(['name' => 'Wildcard Literal', 'code' => 'GS%REAL_1', 'phone' => '5553000', 'active' => true]);
        $wildcard = $this->search('GS%REAL_')->assertOk()->json('results');
        $this->assertContains('Wildcard Literal', collect($wildcard)->pluck('label')->all());

        $empty = $this->search('NO-SUCH-SEARCH-'.Str::random(12))->assertOk()->json('results');
        $this->assertSame([], $empty);
    }

    public function test_global_search_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->search('PO')->assertOk();
        }

        $this->search('PO')->assertTooManyRequests();
    }
}
