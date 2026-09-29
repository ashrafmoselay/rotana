<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Item;
use App\Models\PurchaseOrderVehicle;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class MultipleOrderVehiclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Session::start();
        $this->withSession(['_token' => csrf_token()])->withHeader('X-CSRF-TOKEN', csrf_token());
        config(['rotana.demo_password' => 'Testing-Rotana-2026']);
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
    }

    public function test_vehicle_order_links_each_line_to_one_of_its_multiple_vehicles(): void
    {
        $vehicles = Vehicle::where('branch_id', 1)->take(2)->get();
        if ($vehicles->count() < 2) {
            $source = $vehicles->firstOrFail();
            $vehicles->push(Vehicle::create([
                'plate' => 'اختبار متعدد 2',
                'plate_key' => 'MULTI-VEHICLE-2',
                'vin' => 'MULTIVEHICLETEST2',
                'model' => $source->model,
                'year' => $source->year,
                'color' => $source->color,
                'odometer' => $source->odometer + 1,
                'branch_id' => $source->branch_id,
                'cost_center_id' => $source->cost_center_id,
                'active' => true,
            ]));
        }
        $items = Item::where('active', true)->take(2)->get();

        $order = $this->postJson('/api/orders', [
            'category' => 'maintenance',
            'branch_id' => 1,
            'cost_center_id' => CostCenter::firstOrFail()->id,
            'supplier_id' => Supplier::firstOrFail()->id,
            'date' => today()->toDateString(),
            'priority' => 'normal',
            'payment_timing' => 'after_receipt',
            'tax_percent' => '15',
            'vehicles' => [
                ['vehicle_id' => $vehicles[0]->id, 'odometer' => $vehicles[0]->odometer],
                ['vehicle_id' => $vehicles[1]->id, 'odometer' => $vehicles[1]->odometer],
            ],
            'lines' => [
                ['item_id' => $items[0]->id, 'vehicle_id' => $vehicles[0]->id, 'quantity' => '1', 'unit_price' => '10'],
                ['item_id' => $items[1]->id, 'vehicle_id' => $vehicles[1]->id, 'quantity' => '2', 'unit_price' => '20'],
            ],
        ])->assertCreated()->assertJsonPath('total_minor', 5750)->json();

        $this->assertSame(2, PurchaseOrderVehicle::where('purchase_order_id', $order['id'])->count());
        $this->assertDatabaseHas('order_lines', ['purchase_order_id' => $order['id'], 'item_id' => $items[0]->id, 'vehicle_id' => $vehicles[0]->id]);
        $this->assertDatabaseHas('order_lines', ['purchase_order_id' => $order['id'], 'item_id' => $items[1]->id, 'vehicle_id' => $vehicles[1]->id]);
        $this->post('/api/orders/'.$order['id'].'/media', [
            'collection' => 'photos_before',
            'label' => 'front',
            'vehicle_id' => $vehicles[1]->id,
            'file' => UploadedFile::fake()->image('vehicle-two-front.jpg'),
        ])->assertCreated();
        $this->getJson('/api/orders/'.$order['id'])->assertOk()
            ->assertJsonCount(2, 'order_vehicles')
            ->assertJsonPath('lines.0.vehicle_id', $vehicles[0]->id)
            ->assertJsonPath('media.0.vehicle_id', $vehicles[1]->id);
        $listed = collect($this->getJson('/api/orders?draw=1&start=0&length=100&vehicle_id='.$vehicles[1]->id)->assertOk()->json('data'))
            ->firstWhere('id', $order['id']);
        $this->assertStringContainsString($vehicles[0]->plate, $listed['vehicles']);
        $this->assertStringContainsString($vehicles[1]->plate, $listed['vehicles']);
        $this->assertSame(2, $listed['vehicles_count']);
    }
}
