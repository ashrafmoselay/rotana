<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\MaintenanceCard;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MaintenanceAndVehicleListingTest extends TestCase
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

    private function cardsParams(array $extra = []): array
    {
        return array_replace_recursive([
            'draw' => 41,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 0, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'number', 'name' => 'number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'vehicle.plate', 'name' => 'vehicle.plate', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'date', 'name' => 'date', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'type', 'name' => 'type', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'odometer', 'name' => 'odometer', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'status', 'name' => 'status', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function cardsDataTable(array $params = [])
    {
        return $this->getJson('/api/cards?'.http_build_query($this->cardsParams($params)));
    }

    private function vehiclesParams(array $extra = []): array
    {
        return array_replace_recursive([
            'draw' => 42,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 1, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id', 'name' => 'id', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'plate', 'name' => 'plate', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'model', 'name' => 'model', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'branch.name', 'name' => 'branch.name', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'year', 'name' => 'year', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'odometer', 'name' => 'odometer', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'active', 'name' => 'active', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function vehiclesDataTable(array $params = [])
    {
        return $this->getJson('/api/masters/vehicles?'.http_build_query($this->vehiclesParams($params)));
    }

    private function createVehicle(int $branchId, array $extra = []): Vehicle
    {
        $suffix = Str::upper(Str::random(8));

        return Vehicle::create(array_replace([
            'plate' => 'TEST-'.$branchId.'-'.$suffix,
            'plate_key' => 'TEST'.$branchId.$suffix,
            'vin' => 'VIN'.$branchId.$suffix,
            'model' => 'اختبار '.$branchId,
            'year' => 2026,
            'color' => 'أبيض',
            'odometer' => 12345,
            'branch_id' => $branchId,
            'cost_center_id' => CostCenter::firstOrFail()->id,
            'active' => true,
        ], $extra));
    }

    private function createCard(Vehicle $vehicle, array $extra = []): MaintenanceCard
    {
        $card = MaintenanceCard::create(array_replace([
            'number' => 'MC-TEST-'.Str::upper(Str::random(8)),
            'vehicle_id' => $vehicle->id,
            'branch_id' => $vehicle->branch_id,
            'created_by' => User::where('email', 'admin@rotana.test')->firstOrFail()->id,
            'date' => today()->toDateString(),
            'type' => 'صيانة دورية',
            'odometer' => $vehicle->odometer,
            'status' => 'pending',
            'notes' => 'اختبار جدول كروت الصيانة',
        ], $extra));

        return $card;
    }

    public function test_maintenance_cards_listing_guards_permission_and_returns_datatables_shape(): void
    {
        auth()->logout();
        $this->cardsDataTable()->assertUnauthorized();

        $blocked = User::create(['name' => 'No card access', 'email' => 'no-cards@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->cardsDataTable()->assertForbidden();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->cardsDataTable()
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_maintenance_cards_listing_respects_branch_scope_filters_and_search(): void
    {
        $localVehicle = $this->createVehicle(1, ['plate' => 'CARD-LOCAL-123', 'plate_key' => 'CARDLOCAL123']);
        $foreignVehicle = $this->createVehicle(2, ['plate' => 'CARD-FOREIGN-456', 'plate_key' => 'CARDFOREIGN456']);
        $local = $this->createCard($localVehicle, ['number' => 'MC-LOCAL-SCOPE', 'status' => 'waiting_parts']);
        $foreign = $this->createCard($foreignVehicle, ['number' => 'MC-FOREIGN-SCOPE', 'status' => 'waiting_parts']);

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $limited = $this->cardsDataTable([
            'length' => 100,
            'status' => 'waiting_parts',
            'search' => ['value' => 'CARD-', 'regex' => 'false'],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ])->assertOk()->json();

        $numbers = collect($limited['data'])->pluck('number')->all();
        $this->assertContains($local->number, $numbers);
        $this->assertNotContains($foreign->number, $numbers);
        $this->assertSame('CARD-LOCAL-123', $limited['data'][0]['vehicle']['plate'] ?? null);
        $this->assertIsInt($limited['data'][0]['odometer']);
        $this->assertContains($limited['data'][0]['status'], ['pending', 'waiting_parts', 'in_progress', 'completed', 'closed']);

        $this->getJson('/api/cards?'.http_build_query($this->cardsParams(['branch_id' => 2])))
            ->assertForbidden();
    }

    public function test_maintenance_cards_listing_caps_page_length_and_handles_empty_and_unusual_search(): void
    {
        $vehicle = Vehicle::where('branch_id', 1)->firstOrFail();
        for ($i = 0; $i < 105; $i++) {
            $this->createCard($vehicle, ['number' => 'MC-CAP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'odometer' => 50000 + $i]);
        }

        $capped = $this->cardsDataTable(['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($capped['data']));

        $empty = $this->cardsDataTable(['search' => ['value' => 'NO-SUCH-CARD-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
        $this->assertSame(0, $empty['recordsFiltered']);
        $this->assertSame([], $empty['data']);

        $this->cardsDataTable(['search' => ['value' => str_repeat('%_', 120), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_vehicle_listing_guards_permission_scope_filters_and_types(): void
    {
        auth()->logout();
        $this->vehiclesDataTable()->assertUnauthorized();

        $blocked = User::create(['name' => 'No vehicle access', 'email' => 'no-vehicles@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);
        $this->actingAs($blocked);
        $this->vehiclesDataTable()->assertForbidden();

        $local = $this->createVehicle(1, ['plate' => 'VH-LOCAL-123', 'plate_key' => 'VHLOCAL123', 'odometer' => 76543, 'active' => false]);
        $foreign = $this->createVehicle(2, ['plate' => 'VH-FOREIGN-456', 'plate_key' => 'VHFOREIGN456']);

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $limited = $this->vehiclesDataTable([
            'length' => 100,
            'branch_id' => 1,
            'search' => ['value' => 'VH-', 'regex' => 'false'],
        ])->assertOk()->json();

        $plates = collect($limited['data'])->pluck('plate')->all();
        $this->assertContains($local->plate, $plates);
        $this->assertNotContains($foreign->plate, $plates);
        $row = collect($limited['data'])->firstWhere('plate', $local->plate);
        $this->assertIsInt($row['odometer']);
        $this->assertIsBool($row['active']);
        $this->assertSame('VH-LOCAL-123', $row['plate']);

        $this->getJson('/api/masters/vehicles?'.http_build_query($this->vehiclesParams(['branch_id' => 2])))
            ->assertForbidden();
    }

    public function test_vehicle_listing_caps_page_length_and_handles_empty_and_unusual_search(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->createVehicle(1, [
                'plate' => 'VH-CAP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'plate_key' => 'VHCAP'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'odometer' => $i,
            ]);
        }

        $capped = $this->vehiclesDataTable(['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($capped['data']));

        $empty = $this->vehiclesDataTable(['search' => ['value' => 'NO-SUCH-VEHICLE-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
        $this->assertSame(0, $empty['recordsFiltered']);
        $this->assertSame([], $empty['data']);

        $this->vehiclesDataTable(['search' => ['value' => str_repeat('%_', 120), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }
}
