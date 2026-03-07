<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Seed kategori barang berdasarkan data dari aplikasi SID Retail PRO.
     *
     * Mapping dari SID:
     *   kode SID        → name (dirapikan)     → slug
     *   SPAREPART        → Sparepart            → sparepart
     *   BAN              → Ban                  → ban
     *   OLI              → Oli                  → oli
     *   BAUD             → Baud                 → baud
     *   BOSH             → Bosh                 → bosh
     *   VARIASI          → Variasi              → variasi
     *   JASA             → Jasa                 → jasa
     *   LAIN-LAIN        → Lain-Lain            → lain-lain
     *
     * Catatan: "LAIN2" di SID merupakan duplikat dari "LAIN-LAIN", digabung jadi satu.
     */
    public function run(): void
    {
        $tenant = Tenant::first();
        if (!$tenant) {
            $this->command->error('Tenant belum ada. Jalankan DatabaseSeeder terlebih dahulu.');
            return;
        }

        // Kategori dari SID — disesuaikan nama agar lebih rapi
        $sidCategories = [
            ['code' => 'SPAREPART',  'name' => 'Sparepart',  'description' => 'Suku cadang motor & mesin'],
            ['code' => 'BAN',        'name' => 'Ban',         'description' => 'Ban luar, ban dalam, dan velg'],
            ['code' => 'OLI',        'name' => 'Oli',         'description' => 'Oli mesin, oli gardan, dan pelumas'],
            ['code' => 'BAUD',       'name' => 'Baud',        'description' => 'Mur, baut, dan pengikat'],
            ['code' => 'BOSH',       'name' => 'Bosh',        'description' => 'Bosh roda, bosh arm, dan bushing'],
            ['code' => 'VARIASI',    'name' => 'Variasi',     'description' => 'Aksesoris dan variasi motor'],
            ['code' => 'JASA',       'name' => 'Jasa',        'description' => 'Jasa servis, pemasangan, dan perbaikan'],
            ['code' => 'LAIN-LAIN',  'name' => 'Lain-Lain',   'description' => 'Barang lainnya yang tidak termasuk kategori di atas'],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($sidCategories as $cat) {
            $slug = Str::slug($cat['name']);

            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug'      => $slug,
                    'branch_id' => null, // Global category (berlaku untuk semua cabang)
                ],
                [
                    'name'        => $cat['name'],
                    'description' => $cat['description'],
                    'is_active'   => true,
                ]
            );

            if ($category->wasRecentlyCreated) {
                $created++;
                $this->command->info("  ✅ Kategori '{$cat['name']}' (SID: {$cat['code']}) berhasil dibuat");
            } else {
                $skipped++;
                $this->command->warn("  ⏭  Kategori '{$cat['name']}' sudah ada, dilewati");
            }
        }

        $this->command->info('');
        $this->command->info("📦 Category Seeder selesai: {$created} dibuat, {$skipped} sudah ada.");
    }
}
