<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Product, StockMovement, Branch};
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

        // Filter by branch
        if ($request->has('branch_id')) {
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
     * Stock In - Add stock to product
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

            $quantityBefore = $product->stock;
            $quantityAfter = $quantityBefore + $validated['quantity'];

            // Update product stock
            $product->increment('stock', $validated['quantity']);

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
                'quantity_after' => $quantityAfter,
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
     * Stock Out - Remove stock from product
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

            // Check if enough stock
            if ($product->stock < $validated['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => "Stok tidak mencukupi. Tersedia: {$product->stock}",
                ], 400);
            }

            $quantityBefore = $product->stock;
            $quantityAfter = $quantityBefore - $validated['quantity'];

            // Update product stock
            $product->decrement('stock', $validated['quantity']);

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
                'quantity_after' => $quantityAfter,
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
     * Stock Adjustment - Adjust stock to specific value
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

            $quantityBefore = $product->stock;
            $quantityAfter = $validated['new_quantity'];
            $difference = $quantityAfter - $quantityBefore;

            // Update product stock
            $product->update(['stock' => $quantityAfter]);

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
     * Get stock summary per product
     */
    public function summary(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $query = Product::where('tenant_id', $tenantId)
            ->select('id', 'sku', 'name', 'stock', 'min_stock', 'unit')
            ->withCount(['stockMovements as total_in' => function ($q) {
                $q->where('movement_type', 'in');
            }])
            ->withCount(['stockMovements as total_out' => function ($q) {
                $q->where('movement_type', 'out');
            }]);

        // Low stock filter
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->paginate(20);

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
