<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders.delete', 'inventory.delete', 'masters.delete'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if ($admin = Role::where('name', 'admin')->where('guard_name', 'web')->first()) {
            $admin->givePermissionTo(['orders.delete', 'inventory.delete', 'masters.delete']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if ($admin = Role::where('name', 'admin')->where('guard_name', 'web')->first()) {
            $admin->revokePermissionTo(['orders.delete', 'inventory.delete', 'masters.delete']);
        }
        Permission::whereIn('name', ['orders.delete', 'inventory.delete', 'masters.delete'])->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
