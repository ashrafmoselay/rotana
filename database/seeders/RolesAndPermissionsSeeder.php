<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (config('rotana.permissions') as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $base = ['dashboard.view', 'orders.view', 'vehicles.view', 'suppliers.view', 'items.view', 'cards.view', 'inventory.view', 'excel.export'];
        $roles = ['admin' => config('rotana.permissions'), 'employee' => array_merge($base, ['orders.create', 'orders.update', 'orders.submit', 'media.upload', 'vehicles.manage', 'suppliers.manage']), 'accountant' => array_merge($base, ['orders.review', 'orders.reject', 'orders.match', 'invoices.manage', 'media.upload', 'reports.view']), 'manager' => array_merge($base, ['orders.approve_manager', 'orders.reject', 'reports.view']), 'supervisor' => array_merge($base, ['orders.approve_supervisor', 'orders.reject']), 'treasurer' => array_merge($base, ['payments.create', 'orders.close', 'media.upload']), 'warehouse' => array_merge($base, ['receipts.manage', 'inventory.issue', 'inventory.transfer', 'inventory.return', 'inventory.adjust', 'items.manage', 'excel.import']), 'maintenance' => array_merge($base, ['cards.manage', 'orders.create', 'orders.update', 'orders.submit', 'media.upload']), 'auditor' => array_merge($base, ['activity.view', 'reports.view'])];
        foreach ($roles as $name => $permissions) {
            Role::findOrCreate($name, 'web')->syncPermissions($permissions);
        }
    }
}
