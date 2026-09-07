<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminListingSecurityTest extends TestCase
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

    private function usersParams(array $extra = []): array
    {
        return array_replace_recursive([
            'draw' => 45,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'email', 'name' => 'email', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'roles', 'name' => 'roles', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'active', 'name' => 'active', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'all_branches', 'name' => 'all_branches', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function usersDataTable(array $params = [])
    {
        return $this->getJson('/api/admin/users?'.http_build_query($this->usersParams($params)));
    }

    private function activityParams(array $extra = []): array
    {
        return array_replace_recursive([
            'draw' => 46,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => '', 'regex' => 'false'],
            'order' => [['column' => 0, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'causer.name', 'name' => 'causer.name', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'event_label', 'name' => 'event_label', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'subject_reference', 'name' => 'subject_reference', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
                ['data' => 'details', 'name' => 'details', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
            ],
        ], $extra);
    }

    private function activityDataTable(array $params = [])
    {
        return $this->getJson('/api/admin/activity?'.http_build_query($this->activityParams($params)));
    }

    public function test_admin_user_listing_guards_permissions_and_hides_sensitive_fields(): void
    {
        auth()->logout();
        $this->usersDataTable()->assertUnauthorized();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->usersDataTable()->assertForbidden();
        $this->getJson('/api/admin/roles')->assertForbidden();

        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
        $row = $this->usersDataTable()->assertOk()->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data'])->json('data.0');
        $this->assertArrayHasKey('roles', $row);
        $this->assertArrayHasKey('branches', $row);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('remember_token', $row);

        $this->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonStructure(['roles', 'permissions', 'permission_groups', 'permission_catalog', 'role_labels']);
    }

    public function test_branch_limited_user_manager_only_sees_users_in_authorized_branch(): void
    {
        $role = Role::create(['name' => 'limited-user-manager', 'guard_name' => 'web']);
        $role->syncPermissions(['users.manage']);
        $manager = User::create(['name' => 'Limited user manager', 'email' => 'limited-users@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => false]);
        $manager->assignRole($role);
        $manager->branches()->sync([1]);

        $visible = User::create(['name' => 'Visible Branch User', 'email' => 'visible-branch@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => false]);
        $visible->branches()->sync([1]);
        $hidden = User::create(['name' => 'Hidden Branch User', 'email' => 'hidden-branch@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => false]);
        $hidden->branches()->sync([2]);
        $global = User::create(['name' => 'Hidden Global User', 'email' => 'hidden-global@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => true]);

        $this->actingAs($manager);
        $response = $this->usersDataTable([
            'length' => 100,
            'search' => ['value' => 'User', 'regex' => 'false'],
        ])->assertOk()->json();

        $emails = collect($response['data'])->pluck('email')->all();
        $this->assertContains($visible->email, $emails);
        $this->assertNotContains($hidden->email, $emails);
        $this->assertNotContains($global->email, $emails);
    }

    public function test_admin_user_listing_caps_searches_and_preserves_admin_protections(): void
    {
        for ($i = 0; $i < 105; $i++) {
            User::create(['name' => 'User Cap '.$i, 'email' => 'user-cap-'.$i.'@example.test', 'password' => 'Testing-Rotana-2026', 'active' => (bool) ($i % 2), 'all_branches' => true]);
        }

        $capped = $this->usersDataTable(['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($capped['data']));

        $empty = $this->usersDataTable(['search' => ['value' => 'NO-SUCH-USER-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
        $this->assertSame([], $empty['data']);

        $this->usersDataTable(['search' => ['value' => str_repeat('%_', 120), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);

        $admin = User::where('email', 'admin@rotana.test')->firstOrFail();
        $this->putJson('/api/admin/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'active' => false, 'all_branches' => true, 'roles' => ['admin'], 'branch_ids' => []])
            ->assertUnprocessable();
        $this->putJson('/api/admin/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'active' => true, 'all_branches' => false, 'roles' => ['admin'], 'branch_ids' => [1]])
            ->assertUnprocessable();
    }

    public function test_activity_listing_guards_scope_caps_and_excludes_raw_properties(): void
    {
        auth()->logout();
        $this->activityDataTable()->assertUnauthorized();

        $this->actingAs(User::where('email', 'employee@rotana.test')->firstOrFail());
        $this->activityDataTable()->assertForbidden();

        $admin = User::where('email', 'admin@rotana.test')->firstOrFail();
        $this->actingAs($admin);
        Audit::record('test.local.activity', Branch::findOrFail(1), ['branch_id' => 1, 'reference' => 'ACT-LOCAL-1', 'old' => ['name' => 'قبل'], 'new' => ['name' => 'بعد']]);
        Audit::record('test.foreign.activity', Branch::findOrFail(2), ['branch_id' => 2, 'reference' => 'ACT-FOREIGN-2', 'token' => 'secret-token-value']);
        for ($i = 0; $i < 105; $i++) {
            Audit::record('test.activity.cap', null, ['branch_id' => 1, 'reference' => 'ACT-CAP-'.$i]);
        }

        $limitedRole = Role::create(['name' => 'limited-activity-viewer', 'guard_name' => 'web']);
        $limitedRole->syncPermissions(['activity.view']);
        $limited = User::create(['name' => 'Limited activity user', 'email' => 'limited-activity@example.test', 'password' => 'Testing-Rotana-2026', 'active' => true, 'all_branches' => false]);
        $limited->assignRole($limitedRole);
        $limited->branches()->sync([1]);

        $this->actingAs($limited);
        $response = $this->activityDataTable(['length' => 1000])->assertOk()->json();
        $this->assertLessThanOrEqual(100, count($response['data']));
        $this->assertNotContains('ACT-FOREIGN-2', collect($response['data'])->pluck('details.details.*.value')->flatten()->all());
        $this->assertArrayNotHasKey('properties', $response['data'][0]);
        $this->assertArrayNotHasKey('subject', $response['data'][0]);
        $this->assertStringNotContainsString('secret-token-value', json_encode($response['data'], JSON_UNESCAPED_UNICODE));

        $empty = $this->activityDataTable(['search' => ['value' => 'NO-SUCH-ACTIVITY-'.Str::random(12), 'regex' => 'false']])->assertOk()->json();
        $this->assertSame([], $empty['data']);

        $this->activityDataTable(['search' => ['value' => str_repeat('%_', 120), 'regex' => 'false']])
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }
}
