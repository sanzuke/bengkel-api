<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchStock;
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

        // 4. Create master products (1 per SKU, no branch duplication)
        $masterProducts = [
            [
                'name' => 'Oli MPX1',
                'sku' => 'OIL-MPX1',
                'category_slug' => 'oli-pelumas',
                'type' => 'product',
                'price' => 55000,
                'stock' => 50,
            ],
            [
                'name' => 'Ban Luar 80/90-14',
                'sku' => 'BAN-8090',
                'category_slug' => 'ban-velg',
                'type' => 'product',
                'price' => 220000,
                'stock' => 20,
            ],
            [
                'name' => 'Service Ringan',
                'sku' => 'SVC-LGT',
                'category_slug' => 'jasa-service',
                'type' => 'service',
                'price' => 45000,
                'stock' => 0,
            ],
            [
                'name' => 'Paket Bundling Oli + Service',
                'sku' => 'BDL-01',
                'category_slug' => 'aksesoris',
                'type' => 'product',
                'price' => 90000,
                'stock' => 100,
            ],
        ];

        foreach ($masterProducts as $prodData) {
            $category = Category::where('tenant_id', $tenant->id)
                ->where('slug', $prodData['category_slug'])
                ->first();

            if (!$category) {
                $this->command->warn("Category {$prodData['category_slug']} not found for product {$prodData['name']}");
                continue;
            }

            $product = Product::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'sku' => $prodData['sku'],
                ],
                [
                    'name' => $prodData['name'],
                    'category_id' => $category->id,
                    'type' => $prodData['type'],
                    'unit' => $prodData['type'] == 'service' ? 'jasa' : 'pcs',
                    'purchase_price' => $prodData['price'] * 0.75,
                    'selling_price' => $prodData['price'],
                    'is_active' => true,
                ]
            );

            // 5. Create branch_stocks for each branch
            foreach ($branches as $branch) {
                BranchStock::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'branch_id'  => $branch->id,
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'stock'     => $prodData['stock'],
                        'min_stock' => 5,
                    ]
                );
            }
        }

        $this->command->info('Product Seeder Completed!');
    }
}
