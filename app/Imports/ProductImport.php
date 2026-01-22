<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

class ProductImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    protected int $tenantId;
    protected array $imported = [];
    protected array $errors = [];
    protected array $validated = [];

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Validate a single row
     */
    protected function validateRow(array $row, int $rowNumber): bool
    {
        $rules = [
            'sku' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'type' => 'nullable|in:product,service,PRODUCT,SERVICE',
            'unit' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'supplier' => 'nullable|string|max:255',
        ];

        $messages = [
            'sku.required' => 'SKU wajib diisi',
            'sku.max' => 'SKU maksimal 50 karakter',
            'name.required' => 'Nama produk wajib diisi',
            'name.max' => 'Nama produk maksimal 255 karakter',
            'selling_price.required' => 'Harga jual wajib diisi',
            'selling_price.numeric' => 'Harga jual harus berupa angka',
            'selling_price.min' => 'Harga jual minimal 0',
            'purchase_price.numeric' => 'Harga beli harus berupa angka',
            'purchase_price.min' => 'Harga beli minimal 0',
            'stock.numeric' => 'Stok harus berupa angka',
            'stock.min' => 'Stok minimal 0',
            'min_stock.integer' => 'Minimum stok harus berupa angka bulat',
            'min_stock.min' => 'Minimum stok minimal 0',
            'type.in' => 'Tipe harus product atau service',
            'unit.max' => 'Unit maksimal 50 karakter',
            'category.max' => 'Kategori maksimal 100 karakter',
            'description.max' => 'Deskripsi maksimal 1000 karakter',
        ];

        $validator = Validator::make($row, $rules, $messages);

        if ($validator->fails()) {
            $errorMessages = [];
            foreach ($validator->errors()->all() as $error) {
                $errorMessages[] = $error;
            }
            
            $this->errors[] = [
                'row' => $rowNumber,
                'sku' => $row['sku'] ?? '-',
                'message' => implode('; ', $errorMessages),
            ];
            return false;
        }

        // Additional business validation
        $additionalErrors = [];

        // Check if selling price is greater than 0 for products
        $type = strtolower($row['type'] ?? 'product');
        $sellingPrice = (float) ($row['selling_price'] ?? 0);
        if ($sellingPrice <= 0 && $type === 'product') {
            $additionalErrors[] = 'Harga jual harus lebih dari 0 untuk produk';
        }

        // Validate that selling price >= purchase price (optional warning)
        $purchasePrice = (float) ($row['purchase_price'] ?? 0);
        if ($purchasePrice > $sellingPrice && $sellingPrice > 0) {
            $additionalErrors[] = 'Harga jual lebih kecil dari harga beli';
        }

        // Check SKU format (alphanumeric and dash only)
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $row['sku'] ?? '')) {
            $additionalErrors[] = 'SKU hanya boleh huruf, angka, dash, dan underscore';
        }

        if (!empty($additionalErrors)) {
            $this->errors[] = [
                'row' => $rowNumber,
                'sku' => $row['sku'] ?? '-',
                'message' => implode('; ', $additionalErrors),
            ];
            return false;
        }

        return true;
    }

    public function collection(Collection $rows)
    {
        // First pass: validate all rows
        $validRows = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because of header row and 0-index
            $rowArray = $row->toArray();
            
            // Skip completely empty rows
            if (empty($rowArray['sku']) && empty($rowArray['name'])) {
                continue;
            }

            if ($this->validateRow($rowArray, $rowNumber)) {
                $validRows[] = ['row' => $rowNumber, 'data' => $rowArray];
            }
        }

        // If there are validation errors, stop here (don't import anything)
        if (!empty($this->errors)) {
            return;
        }

        // Second pass: import validated rows
        foreach ($validRows as $item) {
            $row = $item['data'];
            $rowNumber = $item['row'];
            
            try {
                // Find or create category
                $categoryId = null;
                if (!empty($row['category'])) {
                    $category = Category::firstOrCreate(
                        ['tenant_id' => $this->tenantId, 'slug' => str($row['category'])->slug()],
                        ['name' => $row['category'], 'is_active' => true]
                    );
                    $categoryId = $category->id;
                }

                // Find supplier if provided
                $supplierId = null;
                if (!empty($row['supplier'])) {
                    $supplier = Supplier::where('tenant_id', $this->tenantId)
                        ->where('name', 'ilike', $row['supplier'])
                        ->first();
                    $supplierId = $supplier?->id;
                }

                // Determine type
                $type = strtolower($row['type'] ?? 'product');
                if (!in_array($type, ['product', 'service'])) {
                    $type = 'product';
                }

                // Create or update product
                $product = Product::updateOrCreate(
                    ['tenant_id' => $this->tenantId, 'sku' => trim($row['sku'])],
                    [
                        'category_id' => $categoryId,
                        'supplier_id' => $supplierId,
                        'name' => trim($row['name']),
                        'description' => $row['description'] ?? null,
                        'type' => $type,
                        'unit' => $row['unit'] ?? 'pcs',
                        'min_stock' => (int) ($row['min_stock'] ?? 5),
                        'stock' => (float) ($row['stock'] ?? 0),
                        'purchase_price' => (float) ($row['purchase_price'] ?? 0),
                        'selling_price' => (float) ($row['selling_price'] ?? 0),
                        'is_active' => true,
                    ]
                );

                $this->imported[] = [
                    'row' => $rowNumber,
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'action' => $product->wasRecentlyCreated ? 'created' : 'updated',
                ];
            } catch (\Exception $e) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'sku' => $row['sku'] ?? '-',
                    'message' => 'Database error: ' . $e->getMessage(),
                ];
            }
        }
    }

    public function getImported(): array
    {
        return $this->imported;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
