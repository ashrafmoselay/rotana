<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_log_returns_datatables_shape(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());

        $this->getJson('/api/vehicle-log?draw=7&start=0&length=10&search[value]=')
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }
}
