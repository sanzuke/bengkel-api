<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions grouped by module
        $permissions = [
            // Dashboard
            'dashboard.view',
            
            // Products
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            
            // Categories
            'categories.view',
            'categories.create',
            'categories.edit',
            'categories.delete',
            
            // Sales / POS
            'sales.view',
            'sales.create',
            'sales.void',
            'sales.discount',
            
            // Users
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            
            // Roles
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            
            // Suppliers
            'suppliers.view',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.delete',
            
            // Customers
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            
            // Inventory
            'inventory.view',
            'inventory.stock-in',
            'inventory.stock-out',
            'inventory.adjustment',
            
            // Purchase Orders
            'purchase-orders.view',
            'purchase-orders.create',
            'purchase-orders.edit',
            'purchase-orders.approve',
            'purchase-orders.receive',
            'purchase-orders.cancel',
            
            // Stock Opname
            'stock-opname.view',
            'stock-opname.create',
            'stock-opname.count',
            'stock-opname.complete',
            'stock-opname.cancel',
            
            // Reports
            'reports.sales',
            'reports.inventory',
            'reports.financial',
            'reports.customers',
            
            // Settings
            'settings.view',
            'settings.edit',
            
            // Branches
            'branches.view',
            'branches.create',
            'branches.edit',
            'branches.delete',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create default roles with permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->givePermissionTo([
            'dashboard.view',
            'products.view', 'products.create', 'products.edit', 'products.delete',
            'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
            'sales.view', 'sales.create', 'sales.discount',
            'users.view', 'users.create', 'users.edit',
            'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'inventory.view', 'inventory.stock-in', 'inventory.stock-out', 'inventory.adjustment',
            'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.edit', 'purchase-orders.approve', 'purchase-orders.receive',
            'stock-opname.view', 'stock-opname.create', 'stock-opname.count', 'stock-opname.complete',
            'reports.sales', 'reports.inventory', 'reports.customers',
        ]);

        $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $owner->givePermissionTo([
            'dashboard.view',
            'products.view',
            'sales.view',
            'users.view',
            'inventory.view',
            'purchase-orders.view', 'purchase-orders.approve',
            'stock-opname.view',
            'reports.sales', 'reports.inventory', 'reports.financial', 'reports.customers',
        ]);

        $kasir = Role::firstOrCreate(['name' => 'kasir', 'guard_name' => 'web']);
        $kasir->givePermissionTo([
            'dashboard.view',
            'products.view',
            'sales.view', 'sales.create',
            'customers.view', 'customers.create',
        ]);

        $mekanik = Role::firstOrCreate(['name' => 'mekanik', 'guard_name' => 'web']);
        $mekanik->givePermissionTo([
            'dashboard.view',
            'products.view',
            'inventory.view',
        ]);

        $teknisi = Role::firstOrCreate(['name' => 'teknisi', 'guard_name' => 'web']);
        $teknisi->givePermissionTo([
            'dashboard.view',
            'products.view',
            'inventory.view',
        ]);

        $this->command->info('Permissions and roles seeded successfully!');
    }
}
