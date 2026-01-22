<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{StockOpname, StockOpnameItem, Product, StockMovement};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockOpnameController extends Controller
{
    /**
     * Get all stock opnames with pagination
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = StockOpname::where('tenant_id', $tenantId)
            ->with(['branch', 'creator']);

        // Filter by branch
        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by opname number
        if ($request->has('search')) {
            $query->where('opname_number', 'ilike', "%{$request->search}%");
        }

        $opnames = $query->latest('opname_date')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $opnames,
        ]);
    }

    /**
     * Create a new stock opname
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'opname_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $tenantId, $userId) {
            // Generate opname number
            $lastOpname = StockOpname::where('tenant_id', $tenantId)
                ->orderBy('id', 'desc')
                ->first();
            $counter = $lastOpname ? (int)substr($lastOpname->opname_number, -5) + 1 : 1;
            $opnameNumber = 'SO-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);

            // Create opname
            $opname = StockOpname::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'opname_number' => $opnameNumber,
                'opname_date' => $validated['opname_date'],
                'status' => StockOpname::STATUS_DRAFT,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Load all products and add as opname items with current system stock
            $products = Product::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->get();

            foreach ($products as $product) {
                StockOpnameItem::create([
                    'stock_opname_id' => $opname->id,
                    'product_id' => $product->id,
                    'system_quantity' => $product->stock,
                    'physical_quantity' => null,
                    'difference' => null,
                ]);
            }

            $opname->load(['branch', 'creator', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Stock opname created with all products',
                'data' => $opname,
            ], 201);
        });
    }

    /**
     * Get opname detail
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $opname = StockOpname::where('tenant_id', $tenantId)
            ->with(['branch', 'creator', 'completedBy', 'items.product'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $opname,
        ]);
    }

    /**
     * Start opname (change status to in_progress)
     */
    public function start(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $opname = StockOpname::where('tenant_id', $tenantId)->findOrFail($id);

        if ($opname->status !== StockOpname::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft opnames can be started',
            ], 400);
        }

        $opname->update(['status' => StockOpname::STATUS_IN_PROGRESS]);

        return response()->json([
            'success' => true,
            'message' => 'Stock opname started',
            'data' => $opname,
        ]);
    }

    /**
     * Update item counts
     */
    public function updateItems(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $opname = StockOpname::where('tenant_id', $tenantId)->findOrFail($id);

        if (!in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_IN_PROGRESS])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update completed or cancelled opnames',
            ], 400);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:stock_opname_items,id',
            'items.*.physical_quantity' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        foreach ($validated['items'] as $itemData) {
            $item = StockOpnameItem::find($itemData['item_id']);
            
            if ($item->stock_opname_id !== $opname->id) {
                continue;
            }

            $item->update([
                'physical_quantity' => $itemData['physical_quantity'],
                'difference' => $itemData['physical_quantity'] - $item->system_quantity,
                'notes' => $itemData['notes'] ?? null,
            ]);
        }

        $opname->load('items.product');

        return response()->json([
            'success' => true,
            'message' => 'Item counts updated',
            'data' => $opname,
        ]);
    }

    /**
     * Complete opname - apply adjustments
     */
    public function complete(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        $opname = StockOpname::where('tenant_id', $tenantId)
            ->with('items.product')
            ->findOrFail($id);

        if ($opname->status !== StockOpname::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => 'Only in-progress opnames can be completed',
            ], 400);
        }

        // Check all items are counted
        $uncountedItems = $opname->items->whereNull('physical_quantity')->count();
        if ($uncountedItems > 0) {
            return response()->json([
                'success' => false,
                'message' => "There are {$uncountedItems} items not yet counted",
            ], 400);
        }

        return DB::transaction(function () use ($opname, $tenantId, $userId) {
            // Apply adjustments for items with differences
            foreach ($opname->items as $item) {
                if ($item->difference != 0) {
                    $product = $item->product;
                    $quantityBefore = $product->stock;
                    
                    // Update product stock
                    $product->update(['stock' => $item->physical_quantity]);

                    // Create stock movement
                    StockMovement::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $opname->branch_id,
                        'product_id' => $product->id,
                        'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                        'reference_type' => 'opname',
                        'reference_id' => $opname->id,
                        'reference_number' => $opname->opname_number,
                        'quantity' => $item->difference,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $item->physical_quantity,
                        'notes' => "Stock opname: {$opname->opname_number}",
                        'created_by' => $userId,
                    ]);
                }
            }

            // Update opname status
            $opname->update([
                'status' => StockOpname::STATUS_COMPLETED,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            $opname->load(['branch', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Stock opname completed and adjustments applied',
                'data' => $opname,
            ]);
        });
    }

    /**
     * Cancel opname
     */
    public function cancel(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $opname = StockOpname::where('tenant_id', $tenantId)->findOrFail($id);

        if ($opname->status === StockOpname::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel completed opnames',
            ], 400);
        }

        $opname->update(['status' => StockOpname::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'message' => 'Stock opname cancelled',
            'data' => $opname,
        ]);
    }

    /**
     * Delete opname (only draft)
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $opname = StockOpname::where('tenant_id', $tenantId)->findOrFail($id);

        if ($opname->status !== StockOpname::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Can only delete draft opnames',
            ], 400);
        }

        $opname->items()->delete();
        $opname->delete();

        return response()->json([
            'success' => true,
            'message' => 'Stock opname deleted',
        ]);
    }
}
