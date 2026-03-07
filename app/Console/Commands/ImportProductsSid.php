<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportProductsSid extends Command
{
    protected $signature = 'import:products-sid
                            {file : Path ke file SQL/CSV data barang SID}
                            {--branch= : Kode cabang tujuan (misal: KK, CP). WAJIB}
                            {--delimiter=, : Delimiter CSV (default: koma). Hanya untuk file CSV}
                            {--dry-run : Cek data tanpa menyimpan ke database}
                            {--update-existing : Update produk yang sudah ada (berdasarkan SKU)}';

    protected $description = 'Import data barang dari file SQL/CSV ekspor SID Retail PRO. Buat master produk + stok di cabang tertentu.';

    /**
     * Posisi kolom di SQL INSERT (0-indexed, sesuai urutan SELECT di export_barang_sid.sql)
     */
    private const COL_KODE         = 0;
    private const COL_NAMA         = 1;
    private const COL_KATEGORI     = 2;
    private const COL_SATUAN       = 7;
    private const COL_TOKO         = 21;  // stock toko
    private const COL_HPP          = 23;  // purchase price
    private const COL_HARGA_TOKO   = 24;  // selling price
    private const COL_HARGA_PARTAI = 29;  // wholesale price
    private const COL_JENIS        = 40;  // BARANG or JASA
    private const COL_STOKMIN      = 42;
    private const COL_SUPPLIER     = 52;
    private const COL_KODE_BARCODE = 54;
    private const COL_KET          = 79;  // description

    /**
     * CSV header → kolom mapping (untuk backward compatibility)
     */
    private array $csvColumnMap = [
        'kode'          => 'sku',
        'nama'          => 'name',
        'kategori'      => 'category',
        'satuan'        => 'unit',
        'hpp'           => 'purchase_price',
        'harga_toko'    => 'selling_price',
        'toko'          => 'stock',
        'stokmin'       => 'min_stock',
        'jenis'         => 'type',
        'kode_barcode'  => 'barcode',
        'ket'           => 'description',
        'supplier'      => 'supplier',
    ];

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $dryRun = $this->option('dry-run');
        $updateExisting = $this->option('update-existing');
        $branchCode = $this->option('branch');

        // ── Validasi file ──
        if (!file_exists($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");
            return 1;
        }

        // ── Tenant ──
        $tenant = Tenant::first();
        if (!$tenant) {
            $this->error('Tenant belum ada. Jalankan DatabaseSeeder terlebih dahulu.');
            return 1;
        }

        // ── Branch (WAJIB) ──
        if (!$branchCode) {
            $this->error('Opsi --branch wajib diisi. Contoh: --branch=KK');
            $this->info('Cabang tersedia:');
            $branches = Branch::where('tenant_id', $tenant->id)->get();
            foreach ($branches as $b) {
                $this->info("  {$b->code} — {$b->name}");
            }
            return 1;
        }

        $branch = Branch::where('tenant_id', $tenant->id)
            ->where('code', strtoupper($branchCode))
            ->first();

        if (!$branch) {
            $this->error("Cabang dengan kode '{$branchCode}' tidak ditemukan.");
            return 1;
        }

        // ── Deteksi format file ──
        $isSql = str_ends_with(strtolower($filePath), '.sql');

        if ($isSql) {
            return $this->importFromSql($filePath, $tenant, $branch, $dryRun, $updateExisting);
        } else {
            return $this->importFromCsv($filePath, $tenant, $branch, $dryRun, $updateExisting);
        }
    }

    /**
     * Import dari file SQL (INSERT INTO barang VALUES (...), ...)
     */
    private function importFromSql(string $filePath, Tenant $tenant, Branch $branch, bool $dryRun, bool $updateExisting): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   IMPORT BARANG SID (SQL) → BENGKEL MERDEKA ║');
        $this->info('╚══════════════════════════════════════════════╝');

        // ── Parse all records from SQL ──
        $this->info('  📄 Parsing SQL file...');
        $records = $this->parseSqlInserts($filePath);
        $totalRecords = count($records);

        $this->info("  📄 File     : {$filePath}");
        $this->info("  🏪 Cabang   : {$branch->name} ({$branch->code})");
        $this->info("  📊 Total    : {$totalRecords} record");
        $this->info("  🔄 Mode     : " . ($dryRun ? 'DRY RUN' : ($updateExisting ? 'INSERT + UPDATE' : 'INSERT saja')));
        $this->info('');

        // ── Pre-load data ──
        $categories = Category::where('tenant_id', $tenant->id)
            ->get()
            ->keyBy(fn($c) => strtoupper(Str::slug($c->name)));

        // Default category for products without one
        $defaultCategorySlug = strtoupper(Str::slug('Lainnya'));
        if (!isset($categories[$defaultCategorySlug])) {
            $defaultCategory = Category::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'lainnya'],
                ['name' => 'Lainnya', 'description' => 'Kategori default', 'is_active' => true]
            );
            $categories[$defaultCategorySlug] = $defaultCategory;
        }
        $defaultCategoryId = $categories[$defaultCategorySlug]->id;

        $suppliers = Supplier::where('tenant_id', $tenant->id)
            ->get()
            ->keyBy(fn($s) => strtoupper(trim($s->name)));

        // ── Process ──
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'stock_created' => 0, 'suppliers_created' => 0];

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Memulai...');
        $bar->start();

        $chunkSize = 500;

        try {
            $chunks = array_chunk($records, $chunkSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                DB::beginTransaction();

                foreach ($chunk as $values) {
                    $bar->advance();

                    $kode = $this->getVal($values, self::COL_KODE);
                    $nama = $this->getVal($values, self::COL_NAMA);

                    if (empty($kode) || empty($nama)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $bar->setMessage($kode);

                    // ── Kategori ──
                    $categoryId = null;
                    $kategoriSid = strtoupper(trim($this->getVal($values, self::COL_KATEGORI)));
                    if ($kategoriSid) {
                        $categorySlug = strtoupper(Str::slug($kategoriSid));
                        if (isset($categories[$categorySlug])) {
                            $categoryId = $categories[$categorySlug]->id;
                        } elseif (!$dryRun) {
                            $newCategory = Category::create([
                                'tenant_id'   => $tenant->id,
                                'name'        => ucfirst(strtolower($kategoriSid)),
                                'slug'        => Str::slug($kategoriSid),
                                'description' => 'Auto-import dari SID',
                                'is_active'   => true,
                            ]);
                            $categories[$categorySlug] = $newCategory;
                            $categoryId = $newCategory->id;
                        }
                    }

                    // Fallback ke default category
                    if (!$categoryId) {
                        $categoryId = $defaultCategoryId;
                    }

                    // ── Supplier ──
                    $supplierId = null;
                    $supplierName = strtoupper(trim($this->getVal($values, self::COL_SUPPLIER)));
                    if ($supplierName && $supplierName !== '--') {
                        if (isset($suppliers[$supplierName])) {
                            $supplierId = $suppliers[$supplierName]->id;
                        } elseif (!$dryRun) {
                            $newSupplier = Supplier::create([
                                'tenant_id' => $tenant->id,
                                'name'      => ucwords(strtolower($supplierName)),
                                'is_active' => true,
                            ]);
                            $suppliers[$supplierName] = $newSupplier;
                            $supplierId = $newSupplier->id;
                            $stats['suppliers_created']++;
                        }
                    }

                    // ── Parse fields ──
                    $jenis = strtoupper($this->getVal($values, self::COL_JENIS, 'BARANG'));
                    $type = ($jenis === 'JASA') ? 'service' : 'product';

                    $hpp = floatval($this->getVal($values, self::COL_HPP, 0));
                    $hargaToko = floatval($this->getVal($values, self::COL_HARGA_TOKO, 0));
                    if ($hpp <= 0 && $hargaToko > 0) $hpp = $hargaToko * 0.75;

                    $stock = floatval($this->getVal($values, self::COL_TOKO, 0));
                    $minStock = intval($this->getVal($values, self::COL_STOKMIN, 0));

                    $unit = strtoupper($this->getVal($values, self::COL_SATUAN, 'PCS'));
                    if (empty(trim($unit))) $unit = 'PCS';

                    $barcode = $this->getVal($values, self::COL_KODE_BARCODE);
                    if (empty($barcode) || $barcode === $kode) $barcode = null;

                    $description = $this->getVal($values, self::COL_KET);
                    if (empty($description)) $description = null;

                    if ($dryRun) {
                        $stats['created']++;
                        continue;
                    }

                    // ── Master product ──
                    $existing = Product::where('tenant_id', $tenant->id)
                        ->where('sku', $kode)
                        ->first();

                    if ($existing) {
                        if ($updateExisting) {
                            $existing->update([
                                'name'           => $nama,
                                'category_id'    => $categoryId,
                                'supplier_id'    => $supplierId,
                                'type'           => $type,
                                'unit'           => $unit,
                                'purchase_price' => $hpp,
                                'selling_price'  => $hargaToko,
                                'barcode'        => $barcode,
                                'description'    => $description,
                                'is_active'      => true,
                            ]);
                            $stats['updated']++;
                        } else {
                            $stats['skipped']++;
                        }
                        $productId = $existing->id;
                    } else {
                        $product = Product::create([
                            'tenant_id'      => $tenant->id,
                            'category_id'    => $categoryId,
                            'supplier_id'    => $supplierId,
                            'sku'            => $kode,
                            'barcode'        => $barcode,
                            'name'           => $nama,
                            'description'    => $description,
                            'type'           => $type,
                            'unit'           => $unit,
                            'purchase_price' => $hpp,
                            'selling_price'  => $hargaToko,
                            'is_active'      => true,
                        ]);
                        $productId = $product->id;
                        $stats['created']++;
                    }

                    // ── Branch stock ──
                    BranchStock::updateOrCreate(
                        [
                            'product_id' => $productId,
                            'branch_id'  => $branch->id,
                        ],
                        [
                            'tenant_id' => $tenant->id,
                            'stock'     => $stock,
                            'min_stock' => $minStock,
                        ]
                    );
                    $stats['stock_created']++;
                }

                if (!$dryRun) {
                    DB::commit();
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine(2);
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        $bar->setMessage('Selesai!');
        $bar->finish();
        $this->printReport($stats, $dryRun);

        return 0;
    }

    /**
     * Parse SQL INSERT INTO barang VALUES (...), (...), ... format
     * Mengekstrak setiap tuple VALUES menjadi array of string values.
     */
    private function parseSqlInserts(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $records = [];

        // Cari semua tuples: ( "val1", "val2", ... )
        // Regex: match opening ( then capture values until closing )
        preg_match_all('/\(\s*\n?\s*("(?:[^"\\\\]|\\\\.)*"|[^,\)\n]+)\s*(?:,\s*\n?\s*("(?:[^"\\\\]|\\\\.)*"|[^,\)\n]+)\s*)*\)/s', $content, $matches);

        // The regex above is too complex for 124 columns. Let's use a simpler line-by-line approach.
        $records = [];
        $inTuple = false;
        $currentValues = [];

        $handle = fopen($filePath, 'r');
        $buffer = '';

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            // Skip comments, SELECT, FROM, ORDER BY, INSERT INTO lines
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, 'SELECT') ||
                str_starts_with($trimmed, 'FROM') || str_starts_with($trimmed, 'ORDER') ||
                str_starts_with($trimmed, 'INSERT INTO')) {
                continue;
            }

            // Skip empty lines and column names in SELECT
            if (empty($trimmed) || preg_match('/^[a-z_]+,?\s*$/', $trimmed)) {
                continue;
            }

            // Accumulate lines for VALUES parsing
            $buffer .= $line;
        }
        fclose($handle);

        // Now parse the buffer for tuples
        // Each record starts with ( and ends with ), or );
        $pos = 0;
        $len = strlen($buffer);

        while ($pos < $len) {
            // Find next opening paren
            $start = strpos($buffer, '(', $pos);
            if ($start === false) break;

            // Find matching closing paren, respecting quoted strings
            $end = $this->findClosingParen($buffer, $start);
            if ($end === false) break;

            $tupleStr = substr($buffer, $start + 1, $end - $start - 1);
            $values = $this->parseTupleValues($tupleStr);
            if (count($values) > 5) { // At minimum should have kode, nama, etc.
                $records[] = $values;
            }

            $pos = $end + 1;
        }

        return $records;
    }

    /**
     * Find the closing parenthesis, respecting double-quoted strings
     */
    private function findClosingParen(string $buffer, int $openPos): int|false
    {
        $pos = $openPos + 1;
        $len = strlen($buffer);

        while ($pos < $len) {
            $char = $buffer[$pos];

            if ($char === '"') {
                // Skip quoted string
                $pos++;
                while ($pos < $len) {
                    if ($buffer[$pos] === '\\') {
                        $pos += 2; // skip escaped character
                        continue;
                    }
                    if ($buffer[$pos] === '"') {
                        $pos++;
                        break;
                    }
                    $pos++;
                }
                continue;
            }

            if ($char === ')') {
                return $pos;
            }

            $pos++;
        }

        return false;
    }

    /**
     * Parse comma-separated values from a tuple string, handling quoted strings
     */
    private function parseTupleValues(string $tupleStr): array
    {
        $values = [];
        $pos = 0;
        $len = strlen($tupleStr);

        while ($pos < $len) {
            // Skip whitespace and newlines
            while ($pos < $len && in_array($tupleStr[$pos], [' ', "\t", "\n", "\r"])) {
                $pos++;
            }

            if ($pos >= $len) break;

            if ($tupleStr[$pos] === '"') {
                // Quoted value
                $pos++; // skip opening quote
                $value = '';
                while ($pos < $len) {
                    if ($tupleStr[$pos] === '\\' && $pos + 1 < $len) {
                        $value .= $tupleStr[$pos + 1]; // escaped char
                        $pos += 2;
                        continue;
                    }
                    if ($tupleStr[$pos] === '"') {
                        $pos++; // skip closing quote
                        break;
                    }
                    $value .= $tupleStr[$pos];
                    $pos++;
                }
                $values[] = $value;
            } else {
                // Unquoted value (numbers, NULL, etc.)
                $value = '';
                while ($pos < $len && $tupleStr[$pos] !== ',') {
                    if (!in_array($tupleStr[$pos], [' ', "\t", "\n", "\r"])) {
                        $value .= $tupleStr[$pos];
                    }
                    $pos++;
                }
                $values[] = ($value === 'NULL') ? null : $value;
            }

            // Skip comma separator
            while ($pos < $len && in_array($tupleStr[$pos], [' ', "\t", "\n", "\r", ','])) {
                if ($tupleStr[$pos] === ',') {
                    $pos++;
                    break;
                }
                $pos++;
            }
        }

        return $values;
    }

    /**
     * Get value from parsed tuple at given index
     */
    private function getVal(array $values, int $index, string $default = ''): string
    {
        return trim($values[$index] ?? $default);
    }

    /**
     * Import dari file CSV (backward compatible)
     */
    private function importFromCsv(string $filePath, Tenant $tenant, Branch $branch, bool $dryRun, bool $updateExisting): int
    {
        $delimiter = $this->option('delimiter');

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error("Gagal membuka file: {$filePath}");
            return 1;
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            $this->error('File CSV kosong atau format header tidak valid.');
            fclose($handle);
            return 1;
        }

        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

        $requiredColumns = ['kode', 'nama'];
        $missingColumns = array_diff($requiredColumns, $headers);
        if (!empty($missingColumns)) {
            $this->error('Kolom wajib tidak ditemukan: ' . implode(', ', $missingColumns));
            fclose($handle);
            return 1;
        }

        // Count
        $totalLines = 0;
        $countHandle = fopen($filePath, 'r');
        fgetcsv($countHandle, 0, $delimiter);
        while (fgetcsv($countHandle, 0, $delimiter) !== false) {
            $totalLines++;
        }
        fclose($countHandle);

        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   IMPORT BARANG SID (CSV) → BENGKEL MERDEKA ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->info("  📄 File     : {$filePath}");
        $this->info("  🏪 Cabang   : {$branch->name} ({$branch->code})");
        $this->info("  📊 Total    : {$totalLines}");
        $this->info("  🔄 Mode     : " . ($dryRun ? 'DRY RUN' : ($updateExisting ? 'INSERT + UPDATE' : 'INSERT saja')));
        $this->info('');

        $categories = Category::where('tenant_id', $tenant->id)
            ->get()->keyBy(fn($c) => strtoupper(Str::slug($c->name)));

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'stock_created' => 0, 'suppliers_created' => 0];

        $bar = $this->output->createProgressBar($totalLines);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Memulai...');
        $bar->start();

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $bar->advance();

                $data = [];
                foreach ($headers as $index => $header) {
                    $data[$header] = $row[$index] ?? null;
                }

                $kode = trim($data['kode'] ?? '');
                $nama = trim($data['nama'] ?? '');
                if (empty($kode) || empty($nama)) {
                    $stats['skipped']++;
                    continue;
                }

                $bar->setMessage($kode);

                $categoryId = null;
                $kategoriSid = strtoupper(trim($data['kategori'] ?? ''));
                if ($kategoriSid) {
                    $categorySlug = strtoupper(Str::slug($kategoriSid));
                    if (isset($categories[$categorySlug])) {
                        $categoryId = $categories[$categorySlug]->id;
                    } elseif (!$dryRun) {
                        $newCategory = Category::create([
                            'tenant_id'   => $tenant->id,
                            'name'        => ucfirst(strtolower($kategoriSid)),
                            'slug'        => Str::slug($kategoriSid),
                            'description' => 'Auto-import dari SID',
                            'is_active'   => true,
                        ]);
                        $categories[$categorySlug] = $newCategory;
                        $categoryId = $newCategory->id;
                    }
                }

                $jenis = strtoupper(trim($data['jenis'] ?? 'BARANG'));
                $type = ($jenis === 'JASA') ? 'service' : 'product';

                $hpp = floatval($data['hpp'] ?? 0);
                $hargaToko = floatval($data['harga_toko'] ?? 0);
                if ($hpp <= 0 && $hargaToko > 0) $hpp = $hargaToko * 0.75;

                $stock = floatval($data['toko'] ?? 0);
                $minStock = intval($data['stokmin'] ?? 0);

                $unit = strtoupper(trim($data['satuan'] ?? 'PCS'));
                if (empty($unit)) $unit = 'PCS';

                $barcode = trim($data['kode_barcode'] ?? '');
                if (empty($barcode) || $barcode === $kode) $barcode = null;

                $description = trim($data['ket'] ?? '');
                if (empty($description)) $description = null;

                if ($dryRun) {
                    $stats['created']++;
                    continue;
                }

                $existing = Product::where('tenant_id', $tenant->id)
                    ->where('sku', $kode)->first();

                if ($existing) {
                    if ($updateExisting) {
                        $existing->update([
                            'name' => $nama, 'category_id' => $categoryId, 'type' => $type,
                            'unit' => $unit, 'purchase_price' => $hpp, 'selling_price' => $hargaToko,
                            'barcode' => $barcode, 'description' => $description, 'is_active' => true,
                        ]);
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                    $productId = $existing->id;
                } else {
                    $product = Product::create([
                        'tenant_id' => $tenant->id, 'category_id' => $categoryId,
                        'sku' => $kode, 'barcode' => $barcode, 'name' => $nama,
                        'description' => $description, 'type' => $type, 'unit' => $unit,
                        'purchase_price' => $hpp, 'selling_price' => $hargaToko, 'is_active' => true,
                    ]);
                    $productId = $product->id;
                    $stats['created']++;
                }

                BranchStock::updateOrCreate(
                    ['product_id' => $productId, 'branch_id' => $branch->id],
                    ['tenant_id' => $tenant->id, 'stock' => $stock, 'min_stock' => $minStock]
                );
                $stats['stock_created']++;
            }

            if (!$dryRun) {
                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine(2);
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        fclose($handle);
        $bar->setMessage('Selesai!');
        $bar->finish();
        $this->printReport($stats, $dryRun);

        return 0;
    }

    private function printReport(array $stats, bool $dryRun): void
    {
        $this->newLine(2);
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║          LAPORAN IMPORT              ║');
        $this->info('╚══════════════════════════════════════╝');
        if ($dryRun) {
            $this->warn('  ⚠️  DRY RUN — tidak ada data yang disimpan');
        }
        $this->info("  ✅ Dibuat       : {$stats['created']} produk");
        $this->info("  🔄 Diupdate     : {$stats['updated']} produk");
        $this->info("  ⏭  Dilewati     : {$stats['skipped']}");
        $this->info("  📦 Stok cabang  : {$stats['stock_created']} record");
        $this->info("  🏭 Supplier baru: {$stats['suppliers_created']}");
        $this->info("  ❌ Error        : {$stats['errors']}");
        $this->info('');
    }
}
