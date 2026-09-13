<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class SystemMaintenanceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->directory = sys_get_temp_dir().'/rotana-maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/public', 0777, true);
        mkdir($this->directory.'/storage/app/public', 0777, true);
        $this->app->usePublicPath($this->directory.'/public');
        $this->app->useStoragePath($this->directory.'/storage');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function login(bool $admin = true, bool $active = true): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->active = $active;
        $user->shouldReceive('hasRole')->with('admin')->andReturn($admin);
        $this->actingAs($user);
    }

    private function expectCommands(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->ordered()->andReturn(0);
        foreach (['cache:clear', 'config:clear', 'route:clear', 'view:clear'] as $command) {
            Artisan::shouldReceive('call')->once()->with($command, [])->ordered()->andReturn(0);
        }
    }

    public function test_guests_non_admins_and_inactive_admins_cannot_run_commands(): void
    {
        Artisan::shouldReceive('call')->never();
        $this->postJson('/api/admin/maintenance')->assertUnauthorized();
        $this->login(false);
        $this->postJson('/api/admin/maintenance')->assertForbidden();
        $this->login(true, false);
        $this->postJson('/api/admin/maintenance')->assertForbidden();
    }

    public function test_admin_runs_commands_and_creates_missing_public_link(): void
    {
        $this->login();
        $this->expectCommands();
        $this->postJson('/api/admin/maintenance')->assertOk()->assertJsonCount(6, 'results')->assertJsonPath('results.5.success', true);
        $this->assertTrue(is_link(public_path('storage')));
        $this->assertSame(realpath(storage_path('app/public')), realpath(public_path('storage')));
    }

    public function test_existing_link_is_preserved(): void
    {
        $this->login();
        symlink(storage_path('app/public'), public_path('storage'));
        $this->expectCommands();
        $this->postJson('/api/admin/maintenance')->assertOk();
        $this->assertSame(storage_path('app/public'), readlink(public_path('storage')));
    }

    public function test_existing_directory_is_preserved_and_reported_as_failure(): void
    {
        $this->login();
        mkdir(public_path('storage'));
        file_put_contents(public_path('storage/existing.txt'), 'keep');
        $this->expectCommands();
        $this->postJson('/api/admin/maintenance')->assertStatus(500)->assertJsonPath('results.5.success', false);
        $this->assertSame('keep', file_get_contents(public_path('storage/existing.txt')));
    }

    public function test_failed_migration_stops_later_steps_and_releases_lock(): void
    {
        $this->login();
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        $this->postJson('/api/admin/maintenance')->assertStatus(500)->assertJsonCount(1, 'results')->assertJsonPath('results.0.success', false);
        $this->assertFalse(is_link(public_path('storage')));
        $lock = fopen(storage_path('app/maintenance.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_concurrent_request_is_rejected_without_running_commands(): void
    {
        $this->login();
        Artisan::shouldReceive('call')->never();
        $lock = fopen(storage_path('app/maintenance.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->postJson('/api/admin/maintenance')->assertStatus(409);
        } finally {
            fclose($lock);
        }
    }

    public function test_database_backup_and_restore_are_limited_to_admins_and_mysql(): void
    {
        $this->get('/api/admin/database-backup')->assertUnauthorized();
        $this->post('/api/admin/database-restore')->assertUnauthorized();

        $this->login(false);
        $this->getJson('/api/admin/database-backup')->assertForbidden();
        $this->postJson('/api/admin/database-restore')->assertForbidden();

        $this->login();
        $this->getJson('/api/admin/database-backup')->assertUnprocessable();
        $this->postJson('/api/admin/database-restore')->assertUnprocessable();
    }
}
