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
            'name' => 'Bengkel Maju Jaya',
            'slug' => 'bengkel-maju-jaya',
            'email' => 'info@bengkel.com',
            'phone' => '081234567890',
            'address' => 'Jl. Raya Utama No. 123, Jakarta Pusat',
            'is_active' => true,
        ]);

        // 2. Create 4 Branches (Multi-Store)
        $branches = [
            ['name' => 'Toko Pusat', 'code' => 'TP', 'is_main' => true],
            ['name' => 'Cabang Timur', 'code' => 'CT', 'is_main' => false],
            ['name' => 'Cabang Barat', 'code' => 'CB', 'is_main' => false],
            ['name' => 'Cabang Selatan', 'code' => 'CS', 'is_main' => false],
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

        // 5. Create Categories
        $categories = [
            'Oli & Pelumas',
            'Spare Part Mesin',
            'Aki & Battery',
            'Ban & Velg',
            'Aksesoris',
        ];

        foreach ($categories as $catName) {
            Category::create([
                'tenant_id' => $tenant->id,
                'name' => $catName,
                'slug' => str($catName)->slug(),
                'is_active' => true,
            ]);
        }

        // 6. Create Products
        $products = [
            ['name' => 'Oli Shell Helix HX7', 'category' => 'Oli & Pelumas', 'sku' => 'OLI-001', 'purchase' => 50000, 'selling' => 70000, 'stock' => 50],
            ['name' => 'Oli Castrol GTX', 'category' => 'Oli & Pelumas', 'sku' => 'OLI-002', 'purchase' => 55000, 'selling' => 75000, 'stock' => 30],
            ['name' => 'Filter Oli Toyota', 'category' => 'Spare Part Mesin', 'sku' => 'SPR-001', 'purchase' => 30000, 'selling' => 45000, 'stock' => 25],
            ['name' => 'Filter Oli Honda', 'category' => 'Spare Part Mesin', 'sku' => 'SPR-002', 'purchase' => 28000, 'selling' => 42000, 'stock' => 20],
            ['name' => 'Aki GS Astra 45Ah', 'category' => 'Aki & Battery', 'sku' => 'AKI-001', 'purchase' => 550000, 'selling' => 650000, 'stock' => 10],
            ['name' => 'Aki Yuasa 65Ah', 'category' => 'Aki & Battery', 'sku' => 'AKI-002', 'purchase' => 750000, 'selling' => 850000, 'stock' => 8],
            ['name' => 'Ban Bridgestone 185/65R15', 'category' => 'Ban & Velg', 'sku' => 'BAN-001', 'purchase' => 700000, 'selling' => 850000, 'stock' => 12],
            ['name' => 'Ban Michelin 195/60R16', 'category' => 'Ban & Velg', 'sku' => 'BAN-002', 'purchase' => 900000, 'selling' => 1100000, 'stock' => 8],
            ['name' => 'Karpet Mobil Universal', 'category' => 'Aksesoris', 'sku' => 'AKS-001', 'purchase' => 150000, 'selling' => 250000, 'stock' => 15],
            ['name' => 'Cover Mobil Sedan', 'category' => 'Aksesoris', 'sku' => 'AKS-002', 'purchase' => 200000, 'selling' => 300000, 'stock' => 10],
        ];

        foreach ($products as $prod) {
            $category = Category::where('name', $prod['category'])->first();
            Product::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'sku' => $prod['sku'],
                'name' => $prod['name'],
                'type' => 'product',
                'unit' => 'pcs',
                'min_stock' => 5,
                'purchase_price' => $prod['purchase'],
                'selling_price' => $prod['selling'],
                'is_active' => true,
            ]);
        }

        // 7. Create Customers
        $customers = [
            ['name' => 'Ahmad Rizki', 'phone' => '08123456789', 'email' => 'ahmad@example.com'],
            ['name' => 'Budi Santoso', 'phone' => '08123456790', 'email' => 'budi@example.com'],
            ['name' => 'Citra Dewi', 'phone' => '08123456791', 'email' => 'citra@example.com'],
            ['name' => 'Dedi Kurniawan', 'phone' => '08123456792', 'email' => 'dedi@example.com'],
            ['name' => 'Eka Putri', 'phone' => '08123456793', 'email' => 'eka@example.com'],
        ];

        foreach ($customers as $index => $cust) {
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
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
        $this->command->info('   - 1 Tenant: Bengkel Maju Jaya');
        $this->command->info('   - 4 Branches: Toko Pusat, Cabang Timur/Barat/Selatan');
        $this->command->info('   - 3 Users: owner@bengkel.com, admin@bengkel.com, kasir@bengkel.com (password: password)');
        $this->command->info('   - 5 Categories');
        $this->command->info('   - 10 Products');
        $this->command->info('   - 5 Customers with vehicles');
    }
}
