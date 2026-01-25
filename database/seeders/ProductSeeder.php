<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Get Main Tenant
        $tenant = Tenant::first();
        if (!$tenant) {
            $this->command->error('No tenant found. Please run DatabaseSeeder first.');
            return;
        }

        // 2. Get All Branches
        $branches = Branch::where('tenant_id', $tenant->id)->get();
        if ($branches->isEmpty()) {
            $this->command->error('No branches found.');
            return;
        }

        // 3. Create Global Categories (if not exist)
        $globalCategories = [
            ['name' => 'Oli & Pelumas', 'slug' => 'oli-pelumas'],
            ['name' => 'Sparepart Mesin', 'slug' => 'sparepart-mesin'],
            ['name' => 'Ban & Velg', 'slug' => 'ban-velg'],
            ['name' => 'Jasa Service', 'slug' => 'jasa-service'],
            ['name' => 'Aki & Battery', 'slug' => 'aki-battery'],
            ['name' => 'Aksesoris', 'slug' => 'aksesoris'],
        ];

        foreach ($globalCategories as $catData) {
            Category::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => $catData['slug'],
                    'branch_id' => null
                ],
                [
                    'name' => $catData['name'],
                    'is_active' => true,
                ]
            );
        }

        // 4. Loop through branches and create products
        foreach ($branches as $branch) {
            $this->command->info("Seeding products for Branch: {$branch->name} ({$branch->code})");

            // 4.1 Create Branch Specific Category
            $branchCat = Category::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => 'promo-' . strtolower($branch->code),
                    'branch_id' => $branch->id
                ],
                [
                    'name' => 'Promo ' . $branch->name,
                    'is_active' => true,
                ]
            );

            // 4.2 Create Products for this Branch
            $products = [
                [
                    'name' => 'Oli MPX1 (Stok ' . $branch->name . ')',
                    'sku' => 'OIL-MPX1-' . $branch->code,
                    'category_slug' => 'oli-pelumas', // Use Global Category
                    'type' => 'product',
                    'price' => 55000,
                    'stock' => 50
                ],
                [
                    'name' => 'Ban Luar 80/90-14 (Stok ' . $branch->name . ')',
                    'sku' => 'BAN-8090-' . $branch->code,
                    'category_slug' => 'ban-velg', // Use Global Category
                    'type' => 'product',
                    'price' => 220000,
                    'stock' => 20
                ],
                [
                    'name' => 'Service Ringan (' . $branch->name . ')',
                    'sku' => 'SVC-LGT-' . $branch->code,
                    'category_slug' => 'jasa-service', // Use Global Category
                    'type' => 'service',
                    'price' => 45000,
                    'stock' => 0
                ],
                [
                    'name' => 'Paket Bundling Oli + Service (' . $branch->name . ')',
                    'sku' => 'BDL-01-' . $branch->code,
                    'category_slug' => $branchCat->slug, // Use Branch Category
                    'type' => 'product', // Treat as product for stock deduction? Or service? Let's say product package
                    'price' => 90000,
                    'stock' => 100
                ]
            ];

            foreach ($products as $prodData) {
                // Find category ID
                // Logic: Find category by slug, prioritized by branch, then global
                $category = Category::where('tenant_id', $tenant->id)
                    ->where('slug', $prodData['category_slug'])
                    ->first();

                if (!$category) {
                    $this->command->warn("Category {$prodData['category_slug']} not found for product {$prodData['name']}");
                    continue;
                }

                Product::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'sku' => $prodData['sku'],
                        'branch_id' => $branch->id // Assign to Branch
                    ],
                    [
                        'name' => $prodData['name'],
                        'category_id' => $category->id,
                        'type' => $prodData['type'],
                        'unit' => $prodData['type'] == 'service' ? 'jasa' : 'pcs',
                        'purchase_price' => $prodData['price'] * 0.75, // 25% margin
                        'selling_price' => $prodData['price'],
                        'stock' => $prodData['stock'],
                        'min_stock' => 5,
                        'is_active' => true,
                    ]
                );
            }
        }
        
        $this->command->info('Product Seeder Completed!');
    }
}
