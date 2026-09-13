<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class TrialDataResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['rotana.demo_password' => 'Testing-Rotana-2026']);
        Storage::fake('private_media');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
    }

    private function payload(): array
    {
        return ['password' => 'Testing-Rotana-2026', 'confirmation' => 'مسح كل بيانات التجربة',
            'token' => $this->getJson('/api/admin/reset-preview')->assertOk()->json('token')];
    }

    public function test_reset_deletes_trial_records_and_files_but_preserves_current_admin_and_permissions(): void
    {
        $admin = auth()->user();
        $roles = DB::table('roles')->get()->toJson();
        $permissions = DB::table('permissions')->get()->toJson();
        $rolePermissions = DB::table('role_has_permissions')->get()->toJson();
        $file = Media::firstOrFail();
        $path = $file->getPathRelativeToRoot();
        Storage::disk($file->disk)->assertExists($path);
        $payload = $this->payload();
        $this->postJson('/api/admin/reset-data', $payload)->assertOk()->assertJsonPath('file_cleanup_failures', 0);
        foreach (['purchase_orders', 'order_lines', 'approval_events', 'payments', 'supplier_invoices', 'invoice_lines', 'receipts', 'receipt_lines', 'stock_movements', 'stock_movement_lines', 'stock_balances', 'maintenance_cards', 'media', 'vehicles', 'items', 'suppliers', 'warehouses', 'branch_user', 'branches', 'cost_centers', 'regions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($admin->password, User::findOrFail($admin->id)->password);
        $this->assertTrue(User::findOrFail($admin->id)->hasRole('admin'));
        $this->assertSame($roles, DB::table('roles')->get()->toJson());
        $this->assertSame($permissions, DB::table('permissions')->get()->toJson());
        $this->assertSame($rolePermissions, DB::table('role_has_permissions')->get()->toJson());
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'system.trial_data_reset', 'causer_id' => $admin->id]);
        Storage::disk($file->disk)->assertMissing($path);
        DB::table('regions')->insert(['name' => 'بيانات جديدة بعد المسح']);
        $this->postJson('/api/admin/reset-data', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('regions', 1);
    }

    public function test_confirmation_password_and_admin_scope_are_required(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/admin/reset-data', array_replace($payload, ['password' => 'wrong']))->assertUnprocessable();
        $this->postJson('/api/admin/reset-data', array_replace($payload, ['confirmation' => '']))->assertUnprocessable();
        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->getJson('/api/admin/reset-preview')->assertForbidden();
        $this->postJson('/api/admin/reset-data', $payload)->assertForbidden();
        auth()->logout();
        $this->postJson('/api/admin/reset-data', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('purchase_orders', 16);
    }

    public function test_changed_data_requires_a_new_preview_and_maintenance_lock_blocks_reset(): void
    {
        $payload = $this->payload();
        DB::table('regions')->insert(['name' => 'بيانات أثناء التأكيد']);
        $this->postJson('/api/admin/reset-data', $payload)->assertConflict();
        $this->assertDatabaseCount('purchase_orders', 16);
        $payload = $this->payload();
        $lock = fopen(storage_path('app/maintenance.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->postJson('/api/admin/reset-data', $payload)->assertConflict();
            $this->assertDatabaseCount('purchase_orders', 16);
        } finally {
            fclose($lock);
        }
    }

    public function test_database_failure_rolls_back_all_records_and_keeps_files(): void
    {
        $payload = $this->payload();
        $file = Media::firstOrFail();
        DB::listen(function ($query) {
            if ($query->sql === 'delete from "purchase_orders"') {
                throw new \RuntimeException('Simulated reset failure');
            }
        });
        $this->postJson('/api/admin/reset-data', $payload)->assertStatus(500);
        $this->assertDatabaseCount('purchase_orders', 16);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseHas('media', ['id' => $file->id]);
        Storage::disk($file->disk)->assertExists($file->getPathRelativeToRoot());
    }
}
