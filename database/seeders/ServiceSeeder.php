<?php

namespace Database\Seeders;

use App\Models\{Tenant, Category, Product};
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        // Get the first tenant
        $tenant = Tenant::first();
        
        if (!$tenant) {
            $this->command->error('No tenant found. Run DatabaseSeeder first.');
            return;
        }

        // Create Jasa category if not exists
        $jasaCategory = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'jasa-service'],
            [
                'name' => 'Jasa & Service',
                'description' => 'Layanan perawatan dan perbaikan kendaraan',
                'is_active' => true,
            ]
        );

        // Create service products
        $services = [
            ['name' => 'Ganti Oli Mesin', 'sku' => 'SVC-001', 'selling' => 50000, 'desc' => 'Jasa penggantian oli mesin standar'],
            ['name' => 'Ganti Oli + Filter', 'sku' => 'SVC-002', 'selling' => 75000, 'desc' => 'Jasa ganti oli dan filter oli'],
            ['name' => 'Tune Up Ringan', 'sku' => 'SVC-003', 'selling' => 150000, 'desc' => 'Perawatan dasar mesin: busi, filter udara, oli'],
            ['name' => 'Tune Up Besar', 'sku' => 'SVC-004', 'selling' => 350000, 'desc' => 'Perawatan lengkap mesin kendaraan'],
            ['name' => 'Ganti Aki', 'sku' => 'SVC-005', 'selling' => 50000, 'desc' => 'Jasa pemasangan aki baru'],
            ['name' => 'Spooring + Balancing', 'sku' => 'SVC-006', 'selling' => 200000, 'desc' => 'Penyetelan ban dan balancing 4 roda'],
            ['name' => 'Ganti Ban', 'sku' => 'SVC-007', 'selling' => 25000, 'desc' => 'Jasa pemasangan ban per ban'],
            ['name' => 'Cuci Mobil', 'sku' => 'SVC-008', 'selling' => 50000, 'desc' => 'Cuci mobil luar dalam'],
            ['name' => 'Cuci Salon Interior', 'sku' => 'SVC-009', 'selling' => 250000, 'desc' => 'Cuci detail interior dengan vacuum'],
            ['name' => 'Poles Body', 'sku' => 'SVC-010', 'selling' => 500000, 'desc' => 'Poles body mobil full body'],
            ['name' => 'Service AC Ringan', 'sku' => 'SVC-011', 'selling' => 150000, 'desc' => 'Isi freon dan cek sistem AC'],
            ['name' => 'Service AC Besar', 'sku' => 'SVC-012', 'selling' => 400000, 'desc' => 'Bongkar pasang dan perbaikan AC'],
            ['name' => 'Ganti Kampas Rem', 'sku' => 'SVC-013', 'selling' => 100000, 'desc' => 'Jasa penggantian kampas rem'],
            ['name' => 'Service Rem', 'sku' => 'SVC-014', 'selling' => 200000, 'desc' => 'Service sistem rem lengkap'],
            ['name' => 'Kuras Radiator', 'sku' => 'SVC-015', 'selling' => 100000, 'desc' => 'Kuras dan ganti air radiator'],
        ];

        $count = 0;
        foreach ($services as $svc) {
            Product::firstOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $svc['sku']],
                [
                    'category_id' => $jasaCategory->id,
                    'name' => $svc['name'],
                    'description' => $svc['desc'],
                    'type' => 'service',
                    'unit' => 'jasa',
                    'min_stock' => 0,
                    'stock' => 999, // Unlimited for services
                    'purchase_price' => 0,
                    'selling_price' => $svc['selling'],
                    'is_active' => true,
                ]
            );
            $count++;
        }

        $this->command->info("✅ {$count} services created/updated successfully!");
        $this->command->info('📋 Category: Jasa & Service');
        $this->command->info('🔧 Services include: Ganti Oli, Tune Up, Spooring, Cuci, Service AC, dll.');
    }
}
