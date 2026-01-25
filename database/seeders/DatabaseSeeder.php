<?php

namespace Database\Seeders;

use App\Models\{Tenant, Branch, User, Category, Product, Customer, Vehicle};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Main Tenant
        $tenant = Tenant::create([
            'name' => 'Bengkel Merdeka Motor Garut',
            'slug' => 'bengkel-merdeka-motor-garut',
            'email' => 'info@bengkelmerdeka.com',
            'phone' => '081234567890',
            'address' => 'Jl. Raya Merdeka No. 123, Jakarta Garut',
            'is_active' => true,
        ]);

        // 2. Create 4 Branches (Multi-Store)
        $branches = [
            ['name' => 'BMMG Kerkof', 'code' => 'KK', 'is_main' => true],
            ['name' => 'BMMG Ciparay', 'code' => 'CP', 'is_main' => false],
            ['name' => 'BMMG Sanding', 'code' => 'SD', 'is_main' => false],
            ['name' => 'BMMG KarPaw', 'code' => 'KP', 'is_main' => false],
        ];

        foreach ($branches as $branchData) {
            Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $branchData['name'],
                'code' => $branchData['code'],
                'address' => 'Alamat ' . $branchData['name'] . ', Jakarta',
                'phone' => '0812345678' . rand(10, 99),
                'is_main' => $branchData['is_main'],
                'is_active' => true,
            ]);
        }

        // 3. Create Roles
        $ownerRole = Role::create(['name' => 'owner', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $kasirRole = Role::create(['name' => 'kasir', 'guard_name' => 'web']);
        $teknisiRole = Role::create(['name' => 'teknisi', 'guard_name' => 'web']);

        // 4. Create Users
        $owner = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'email' => 'owner@bengkel.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $owner->assignRole($ownerRole);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@bengkel.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole($adminRole);

        $kasir = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kasir',
            'email' => 'kasir@bengkel.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $kasir->assignRole($kasirRole);

        $teknisi = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Teknisi',
            'email' => 'teknisi@bengkel.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $teknisi->assignRole($teknisiRole);

        // 5. Call Seeders
        $this->call([
            PermissionSeeder::class,  // Create permissions and assign to roles
            UserProfileSeeder::class, // Create specific user profiles
            ProductSeeder::class,     // Create products and categories
        ]);

        // 7. Create Customers
        $customers = [
            ['name' => 'Ahmad Rizki', 'phone' => '08123456789', 'email' => 'ahmad@example.com'],
            ['name' => 'Budi Santoso', 'phone' => '08123456790', 'email' => 'budi@example.com'],
            ['name' => 'Citra Dewi', 'phone' => '08123456791', 'email' => 'citra@example.com'],
            ['name' => 'Dedi Kurniawan', 'phone' => '08123456792', 'email' => 'dedi@example.com'],
            ['name' => 'Eka Putri', 'phone' => '08123456793', 'email' => 'eka@example.com'],
        ];

        // Fetch all created branches
        $allBranches = Branch::where('tenant_id', $tenant->id)->get();

        foreach ($customers as $index => $cust) {
            // Assign random branch if available, otherwise null
            $randomBranch = $allBranches->isNotEmpty() ? $allBranches->random() : null;

            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $randomBranch ? $randomBranch->id : null,
                'customer_code' => 'CUST-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'name' => $cust['name'],
                'phone' => $cust['phone'],
                'email' => $cust['email'],
                'address' => 'Alamat ' . $cust['name'],
            ]);

            // Create vehicle for each customer
            Vehicle::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'plate_number' => 'B ' . rand(1000, 9999) . ' XYZ',
                'brand' => ['Toyota', 'Honda', 'Suzuki', 'Daihatsu'][array_rand(['Toyota', 'Honda', 'Suzuki', 'Daihatsu'])],
                'model' => ['Avanza', 'Xenia', 'Jazz', 'Brio'][array_rand(['Avanza', 'Xenia', 'Jazz', 'Brio'])],
                'year' => rand(2018, 2024),
                'color' => ['Putih', 'Hitam', 'Silver', 'Merah'][array_rand(['Putih', 'Hitam', 'Silver', 'Merah'])],
            ]);
        }

        $this->command->info('✅ Demo data seeded successfully!');
        $this->command->info('📊 Created:');
        $this->command->info('   - 1 Tenant: Bengkel Merdeka Motor');
        $this->command->info('   - 4 Branches: BMMG Kerkof, Ciparay, Sanding, KarPaw');
        $this->command->info('   - Users & Profiles (via UserProfileSeeder)');
        $this->command->info('   - Categories & Products (via ProductSeeder - per branch)');
        $this->command->info('   - 5 Customers with vehicles');
    }
}
