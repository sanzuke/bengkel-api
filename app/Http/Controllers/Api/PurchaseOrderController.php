<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{PurchaseOrder, PurchaseOrderItem, Product, StockMovement};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    /**
     * Get all purchase orders with pagination
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = PurchaseOrder::where('tenant_id', $tenantId)
            ->with(['supplier', 'branch', 'creator']);

        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }

        // Search by PO number
        if ($request->has('search')) {
            $query->where('po_number', 'ilike', "%{$request->search}%");
        }

        $orders = $query->latest('order_date')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Create a new purchase order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $tenantId, $userId) {
            // Generate PO number
            $lastPO = PurchaseOrder::where('tenant_id', $tenantId)
                ->orderBy('id', 'desc')
                ->first();
            $counter = $lastPO ? (int)substr($lastPO->po_number, -5) + 1 : 1;
            $poNumber = 'PO-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);

            // Create PO
            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'supplier_id' => $validated['supplier_id'],
                'po_number' => $poNumber,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'discount' => $validated['discount'] ?? 0,
                'tax' => $validated['tax'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Create items
            foreach ($validated['items'] as $item) {
                $subtotal = ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => $subtotal,
                ]);
            }

            // Calculate totals
            $po->calculateTotals();
            $po->load(['supplier', 'branch', 'creator', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $po,
            ], 201);
        });
    }

    /**
     * Get PO detail
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)
            ->with(['supplier', 'branch', 'creator', 'approver', 'items.product'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $po,
        ]);
    }

    /**
     * Update PO (only draft status)
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($id);

        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Can only edit draft purchase orders',
            ], 400);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($po, $validated) {
            // Update PO
            $po->update([
                'supplier_id' => $validated['supplier_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'discount' => $validated['discount'] ?? 0,
                'tax' => $validated['tax'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Delete old items and create new ones
            $po->items()->delete();
            foreach ($validated['items'] as $item) {
                $subtotal = ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => $subtotal,
                ]);
            }

            $po->calculateTotals();
            $po->load(['supplier', 'branch', 'creator', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Purchase order updated successfully',
                'data' => $po,
            ]);
        });
    }

    /**
     * Submit PO for approval
     */
    public function submit(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($id);

        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft orders can be submitted',
            ], 400);
        }

        $po->update(['status' => PurchaseOrder::STATUS_PENDING]);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order submitted for approval',
            'data' => $po,
        ]);
    }

    /**
     * Approve PO
     */
    public function approve(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($id);

        if ($po->status !== PurchaseOrder::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be approved',
            ], 400);
        }

        $po->update([
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order approved',
            'data' => $po,
        ]);
    }

    /**
     * Receive items (add to stock)
     */
    public function receive(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)
            ->with('items.product')
            ->findOrFail($id);

        if (!in_array($po->status, [PurchaseOrder::STATUS_PENDING, PurchaseOrder::STATUS_PARTIAL])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot receive items for this order',
            ], 400);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:purchase_order_items,id',
            'items.*.received_quantity' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($po, $validated, $tenantId, $userId) {
            foreach ($validated['items'] as $itemData) {
                $poItem = PurchaseOrderItem::find($itemData['item_id']);
                
                if ($poItem->purchase_order_id !== $po->id) {
                    continue;
                }

                $receivedQty = $itemData['received_quantity'];
                $remainingQty = $poItem->quantity - $poItem->received_quantity;

                if ($receivedQty > $remainingQty) {
                    $receivedQty = $remainingQty;
                }

                if ($receivedQty > 0) {
                    // Update received quantity
                    $poItem->increment('received_quantity', $receivedQty);

                    // Add stock
                    $product = $poItem->product;
                    $quantityBefore = $product->stock;
                    $product->increment('stock', $receivedQty);

                    // Create stock movement
                    StockMovement::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $po->branch_id,
                        'product_id' => $product->id,
                        'movement_type' => StockMovement::TYPE_IN,
                        'reference_type' => 'purchase',
                        'reference_id' => $po->id,
                        'reference_number' => $po->po_number,
                        'quantity' => $receivedQty,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $product->stock,
                        'unit_cost' => $poItem->unit_price,
                        'notes' => "Received from PO: {$po->po_number}",
                        'created_by' => $userId,
                    ]);
                }
            }

            // Update PO status
            $po->load('items');
            $allReceived = $po->items->every(fn($item) => $item->received_quantity >= $item->quantity);
            $anyReceived = $po->items->contains(fn($item) => $item->received_quantity > 0);

            if ($allReceived) {
                $po->update([
                    'status' => PurchaseOrder::STATUS_RECEIVED,
                    'received_date' => now(),
                ]);
            } elseif ($anyReceived) {
                $po->update(['status' => PurchaseOrder::STATUS_PARTIAL]);
            }

            $po->load(['supplier', 'branch', 'items.product']);

            return response()->json([
                'success' => true,
                'message' => 'Items received and stock updated',
                'data' => $po,
            ]);
        });
    }

    /**
     * Cancel PO
     */
    public function cancel(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($id);

        if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel this order',
            ], 400);
        }

        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order cancelled',
            'data' => $po,
        ]);
    }

    /**
     * Delete PO (only draft)
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $po = PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($id);

        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Can only delete draft purchase orders',
            ], 400);
        }

        $po->items()->delete();
        $po->delete();

        return response()->json([
            'success' => true,
            'message' => 'Purchase order deleted',
        ]);
    }
}
