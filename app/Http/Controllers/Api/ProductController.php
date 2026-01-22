<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\ProductTemplateExport;
use App\Imports\ProductImport;
use App\Models\{Product, Category, Supplier};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    /**
     * Display a listing of products
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = Product::where('tenant_id', $tenantId)
            ->with(['category', 'supplier']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhere('barcode', 'ilike', "%{$search}%");
            });
        }

        // Category filter
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Supplier filter
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Type filter
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Low stock filter
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        $products = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get categories for filter
     */
    public function categories(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $categories = Category::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('id', 'name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get suppliers for filter
     */
    public function suppliers(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $suppliers = Supplier::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers,
        ]);
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'name' => 'required|string|max:255',
            'barcode' => 'nullable|string|max:100',
            'type' => 'required|in:product,service',
            'unit' => 'required|string|max:50',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'stock' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;

        // Generate SKU
        $sku = 'PRD-' . strtoupper(Str::random(8));

        $product = Product::create([
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'stock' => $validated['stock'] ?? 0,
            ...$validated,
        ]);

        $product->load(['category', 'supplier']);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product,
        ], 201);
    }

    /**
     * Display the specified product
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $product = Product::where('tenant_id', $tenantId)
            ->with(['category', 'supplier'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'name' => 'required|string|max:255',
            'barcode' => 'nullable|string|max:100',
            'type' => 'required|in:product,service',
            'unit' => 'required|string|max:50',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'stock' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $tenantId = $request->user()->tenant_id;

        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);
        $product->update($validated);
        $product->load(['category', 'supplier']);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product,
        ]);
    }

    /**
     * Remove the specified product
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Import products from Excel/CSV file
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // Max 10MB
        ]);

        $tenantId = $request->user()->tenant_id;

        try {
            $import = new ProductImport($tenantId);
            Excel::import($import, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => 'Products imported successfully',
                'data' => [
                    'imported' => $import->getImported(),
                    'errors' => $import->getErrors(),
                    'total_imported' => count($import->getImported()),
                    'total_errors' => count($import->getErrors()),
                ],
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                ];
            }
            return response()->json([
                'success' => false,
                'message' => 'Validation errors in import file',
                'errors' => $errors,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download import template
     */
    public function importTemplate()
    {
        return Excel::download(new ProductTemplateExport, 'product_import_template.csv');
    }
}

