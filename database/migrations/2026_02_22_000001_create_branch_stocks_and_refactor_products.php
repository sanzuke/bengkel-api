<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────
        // 1. Buat tabel branch_stocks
        // ────────────────────────────────────────────────
        Schema::create('branch_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->decimal('stock', 15, 2)->default(0);
            $table->integer('min_stock')->default(0);
            $table->decimal('selling_price', 15, 2)->nullable()->comment('Harga jual cabang (null = pakai harga master)');
            $table->decimal('purchase_price', 15, 2)->nullable()->comment('Harga beli cabang (null = pakai harga master)');
            $table->timestamps();

            // 1 produk hanya punya 1 record stok per cabang
            $table->unique(['product_id', 'branch_id']);
            $table->index('tenant_id');
            $table->index('branch_id');
        });

        // ────────────────────────────────────────────────
        // 2. Migrasi data existing: products → branch_stocks
        // ────────────────────────────────────────────────
        $products = DB::table('products')
            ->whereNotNull('branch_id')
            ->get();

        foreach ($products as $product) {
            DB::table('branch_stocks')->insertOrIgnore([
                'tenant_id'      => $product->tenant_id,
                'product_id'     => $product->id,
                'branch_id'      => $product->branch_id,
                'stock'          => $product->stock ?? 0,
                'min_stock'      => $product->min_stock ?? 0,
                'selling_price'  => null, // pakai harga master
                'purchase_price' => null, // pakai harga master
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // Untuk produk tanpa branch_id, buat branch_stocks untuk semua cabang
        $productsWithoutBranch = DB::table('products')
            ->whereNull('branch_id')
            ->get();

        $branches = DB::table('branches')->get();

        foreach ($productsWithoutBranch as $product) {
            foreach ($branches as $branch) {
                if ($branch->tenant_id ?? null) {
                    // Only match tenant
                    if (isset($product->tenant_id) && $product->tenant_id != ($branch->tenant_id ?? $product->tenant_id)) {
                        continue;
                    }
                }
                DB::table('branch_stocks')->insertOrIgnore([
                    'tenant_id'      => $product->tenant_id,
                    'product_id'     => $product->id,
                    'branch_id'      => $branch->id,
                    'stock'          => $product->stock ?? 0,
                    'min_stock'      => $product->min_stock ?? 0,
                    'selling_price'  => null,
                    'purchase_price' => null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }

        // ────────────────────────────────────────────────
        // 3. Deduplicate products: gabungkan berdasarkan tenant_id + sku
        //    Simpan 1 record per SKU, hapus duplikat
        // ────────────────────────────────────────────────

        // Cari SKU yang memiliki duplikat (same tenant + sku, different branch)
        $duplicateSkus = DB::table('products')
            ->select('tenant_id', 'sku', DB::raw('MIN(id) as keep_id'))
            ->groupBy('tenant_id', 'sku')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateSkus as $dup) {
            // Update branch_stocks: ubah product_id dari duplikat ke keep_id
            $duplicateIds = DB::table('products')
                ->where('tenant_id', $dup->tenant_id)
                ->where('sku', $dup->sku)
                ->where('id', '!=', $dup->keep_id)
                ->pluck('id');

            foreach ($duplicateIds as $oldId) {
                // Update branch_stocks product_id
                DB::table('branch_stocks')
                    ->where('product_id', $oldId)
                    ->update(['product_id' => $dup->keep_id]);

                // Update sale_items product_id
                DB::table('sale_items')
                    ->where('product_id', $oldId)
                    ->update(['product_id' => $dup->keep_id]);

                // Update stock_movements product_id
                DB::table('stock_movements')
                    ->where('product_id', $oldId)
                    ->update(['product_id' => $dup->keep_id]);
            }

            // Delete duplicate products
            DB::table('products')
                ->where('tenant_id', $dup->tenant_id)
                ->where('sku', $dup->sku)
                ->where('id', '!=', $dup->keep_id)
                ->delete();

            // Remove duplicate branch_stocks (same product_id + branch_id)
            // Keep the one with highest stock
            $branchStockDups = DB::table('branch_stocks')
                ->select('product_id', 'branch_id', DB::raw('MAX(id) as keep_id'))
                ->where('product_id', $dup->keep_id)
                ->groupBy('product_id', 'branch_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($branchStockDups as $bsd) {
                DB::table('branch_stocks')
                    ->where('product_id', $bsd->product_id)
                    ->where('branch_id', $bsd->branch_id)
                    ->where('id', '!=', $bsd->keep_id)
                    ->delete();
            }
        }

        // ────────────────────────────────────────────────
        // 4. Hapus kolom stock, min_stock, branch_id dari products
        // ────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            // Drop foreign key first
            if (Schema::hasColumn('products', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'stock')) {
                $table->dropColumn('stock');
            }
            if (Schema::hasColumn('products', 'min_stock')) {
                $table->dropColumn('min_stock');
            }
        });

        // ────────────────────────────────────────────────
        // 5. Update SKU constraint: unique per tenant, not globally
        // ────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            // Drop old unique index on sku
            $table->dropUnique(['sku']);
            // Add composite unique: tenant_id + sku
            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        // Restore sku unique constraint
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sku']);
            $table->unique('sku');
        });

        // Add back columns to products
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock', 15, 2)->default(0)->after('unit');
            $table->integer('min_stock')->default(0)->after('stock');
            $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        // Restore data from branch_stocks
        $branchStocks = DB::table('branch_stocks')->get();
        foreach ($branchStocks as $bs) {
            DB::table('products')
                ->where('id', $bs->product_id)
                ->update([
                    'stock'     => $bs->stock,
                    'min_stock' => $bs->min_stock,
                    'branch_id' => $bs->branch_id,
                ]);
        }

        Schema::dropIfExists('branch_stocks');
    }
};
