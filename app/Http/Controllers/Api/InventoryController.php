<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Product, BranchStock, StockMovement, Branch};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Get all stock movements with pagination
     */
    public function movements(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = StockMovement::where('tenant_id', $tenantId)
            ->with(['product', 'branch', 'creator']);

        // Branch filter
        $user = $request->user();
        if (!$user->hasRole('owner')) {
            $branchId = $user->employee->branch_id ?? $user->branches()->first()?->id;
            if ($branchId) {
                $query->where('branch_id', $branchId);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by movement type
        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Search by reference number
        if ($request->has('search')) {
            $query->where('reference_number', 'ilike', "%{$request->search}%");
        }

        $movements = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    /**
     * Stock In - Add stock to product (operates on branch_stocks)
     */
    public function stockIn(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $tenantId, $userId) {
            $product = Product::where('tenant_id', $tenantId)
                ->findOrFail($validated['product_id']);

            // Get or create branch stock record
            $branchStock = BranchStock::firstOrCreate(
                [
                    'product_id' => $validated['product_id'],
                    'branch_id'  => $validated['branch_id'],
                ],
                [
                    'tenant_id' => $tenantId,
                    'stock'     => 0,
                    'min_stock' => 0,
                ]
            );

            $quantityBefore = $branchStock->stock;
            
            // Update branch stock
            $branchStock->increment('stock', $validated['quantity']);
            $branchStock->refresh();

            // Create movement record
            $movement = StockMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'product_id' => $validated['product_id'],
                'movement_type' => StockMovement::TYPE_IN,
                'reference_type' => 'manual',
                'reference_number' => $validated['reference_number'] ?? 'IN-' . date('YmdHis'),
                'quantity' => $validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $branchStock->stock,
                'unit_cost' => $validated['unit_cost'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $movement->load(['product', 'branch', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Stock added successfully',
                'data' => $movement,
            ], 201);
        });
    }

    /**
     * Stock Out - Remove stock from product (operates on branch_stocks)
     */
    public function stockOut(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $tenantId, $userId) {
            $product = Product::where('tenant_id', $tenantId)
                ->findOrFail($validated['product_id']);

            // Get branch stock
            $branchStock = BranchStock::where('product_id', $validated['product_id'])
                ->where('branch_id', $validated['branch_id'])
                ->first();

            $currentStock = $branchStock->stock ?? 0;

            // Check if enough stock
            if ($currentStock < $validated['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => "Stok tidak mencukupi. Tersedia: {$currentStock}",
                ], 400);
            }

            $quantityBefore = $currentStock;

            // Update branch stock
            $branchStock->decrement('stock', $validated['quantity']);
            $branchStock->refresh();

            // Create movement record
            $movement = StockMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'product_id' => $validated['product_id'],
                'movement_type' => StockMovement::TYPE_OUT,
                'reference_type' => 'manual',
                'reference_number' => $validated['reference_number'] ?? 'OUT-' . date('YmdHis'),
                'quantity' => -$validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $branchStock->stock,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $movement->load(['product', 'branch', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Stock removed successfully',
                'data' => $movement,
            ], 201);
        });
    }

    /**
     * Stock Adjustment - Adjust stock to specific value (operates on branch_stocks)
     */
    public function adjustment(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'product_id' => 'required|exists:products,id',
            'new_quantity' => 'required|numeric|min:0',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $tenantId, $userId) {
            $product = Product::where('tenant_id', $tenantId)
                ->findOrFail($validated['product_id']);

            // Get or create branch stock
            $branchStock = BranchStock::firstOrCreate(
                [
                    'product_id' => $validated['product_id'],
                    'branch_id'  => $validated['branch_id'],
                ],
                [
                    'tenant_id' => $tenantId,
                    'stock'     => 0,
                    'min_stock' => 0,
                ]
            );

            $quantityBefore = $branchStock->stock;
            $quantityAfter = $validated['new_quantity'];
            $difference = $quantityAfter - $quantityBefore;

            // Update branch stock
            $branchStock->update(['stock' => $quantityAfter]);

            // Create movement record
            $movement = StockMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'product_id' => $validated['product_id'],
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'reference_type' => 'manual',
                'reference_number' => 'ADJ-' . date('YmdHis'),
                'quantity' => $difference,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'notes' => "Reason: {$validated['reason']}" . ($validated['notes'] ? "\n{$validated['notes']}" : ''),
                'created_by' => $userId,
            ]);

            $movement->load(['product', 'branch', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => $movement,
            ], 201);
        });
    }

    /**
     * Get stock summary per product (now shows branch_stocks data)
     */
    public function summary(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $user = $request->user();

        // Determine branch filter
        $branchId = null;
        if (!$user->hasRole('owner') && $user->employee && $user->employee->branch_id) {
            $branchId = $user->employee->branch_id;
        } elseif ($request->has('branch_id')) {
            $branchId = $request->branch_id;
        }

        $query = Product::where('products.tenant_id', $tenantId)
            ->with('category');

        if ($branchId) {
            // Show products with their stock at this specific branch
            $query->leftJoin('branch_stocks', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_stocks.product_id')
                     ->where('branch_stocks.branch_id', '=', $branchId);
            })
            ->select('products.*',
                'branch_stocks.stock as branch_stock',
                'branch_stocks.reserved_quantity as branch_reserved',
                'branch_stocks.min_stock as branch_min_stock',
                'branch_stocks.selling_price as branch_selling_price',
                'branch_stocks.purchase_price as branch_purchase_price'
            );

            // Low stock filter
            if ($request->boolean('low_stock')) {
                $query->whereNotNull('branch_stocks.id')
                      ->whereRaw('branch_stocks.stock <= branch_stocks.min_stock')
                      ->where('branch_stocks.min_stock', '>', 0);
            }
        } else {
            // Owner view: show all products with aggregated stock
            $query->withSum('branchStocks as total_stock', 'stock')
                  ->withSum('branchStocks as total_reserved', 'reserved_quantity');

            if ($request->boolean('low_stock')) {
                $query->whereHas('branchStocks', function ($q) {
                    $q->whereColumn('stock', '<=', 'min_stock')
                      ->where('min_stock', '>', 0);
                });
            }
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'ilike', "%{$search}%")
                  ->orWhere('products.sku', 'ilike', "%{$search}%");
            });
        }

        $products = $query->orderBy('products.name')->paginate(20);

        // Add computed fields
        $products->each(function ($product) {
            $product->stock = $product->branch_stock ?? $product->total_stock ?? 0;
            $product->reserved = $product->branch_reserved ?? $product->total_reserved ?? 0;
            $product->available_stock = max(0, $product->stock - $product->reserved);
            $product->min_stock = $product->branch_min_stock ?? 0;
        });

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get branches for dropdown
     */
    public function branches(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $branches = Branch::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }
}
