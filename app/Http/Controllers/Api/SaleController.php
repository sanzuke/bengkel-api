<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Sale, SaleDeletionLog, SaleItem, Product, BranchStock, StockMovement};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    /**
     * Get products for POS (search & filter)
     * Now uses branch_stocks for stock availability and per-branch pricing.
     */
    public function searchProducts(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $user = $request->user();

        // Determine branch
        if (!$user->hasRole('owner')) {
            $branchId = $user->employee->branch_id ?? $user->branches()->first()?->id;
            if (!$branchId) {
                return response()->json(['success' => true, 'data' => []]);
            }
        } else {
            $branchId = $request->branch_id;
        }

        $query = Product::where('products.tenant_id', $tenantId)
            ->where('products.is_active', true)
            ->with('category');

        // Join branch_stocks for stock & pricing info
        if ($branchId) {
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
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                  ->orWhere('products.sku', 'like', "%{$search}%")
                  ->orWhere('products.barcode', 'like', "%{$search}%");
            });
        }

        $products = $query->limit(20)->get();

        // Compute effective prices and available stock
        $products->each(function ($product) {
            $totalStock = $product->branch_stock ?? 0;
            $reserved = $product->branch_reserved ?? 0;
            $product->stock = $totalStock;
            $product->reserved = $reserved;
            $product->available_stock = max(0, $totalStock - $reserved);
            $product->effective_selling_price = $product->branch_selling_price ?? $product->selling_price;
            $product->effective_purchase_price = $product->branch_purchase_price ?? $product->purchase_price;
        });

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get sales history with filters
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        
        $query = Sale::where('tenant_id', $tenantId)
            ->with(['branch', 'customer', 'creator']);

        // Search by invoice number
        if ($request->has('search')) {
            $query->where('invoice_number', 'like', "%{$request->search}%");
        }

        // Filter by branch
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

        // Filter by payment status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('sale_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('sale_date', '<=', $request->end_date);
        }

        $sales = $query->latest('sale_date')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $sales,
        ]);
    }

    /**
     * Get sale detail
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $sale = Sale::where('tenant_id', $tenantId)
            ->with(['branch', 'customer', 'vehicle', 'items.product', 'creator'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $sale,
        ]);
    }

    /**
     * Create a new sale transaction
     * Supports payment_status: 'paid' (immediate) or 'pending' (open transaction)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'nullable|exists:customers,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.adjustment_reason' => 'nullable|string|max:500',
            'items.*.warranty_days' => 'nullable|integer|min:0',
            'payment_method' => 'nullable|in:cash,transfer,card,qris',
            'payment_status' => 'nullable|in:pending,paid',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;
        $paymentStatus = $validated['payment_status'] ?? 'paid';

        // payment_method is required for paid transactions
        if ($paymentStatus === 'paid' && empty($validated['payment_method'])) {
            return response()->json([
                'success' => false,
                'message' => 'Metode pembayaran wajib diisi untuk transaksi langsung bayar',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Calculate totals
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $discountPercent = $validated['discount_percent'] ?? 0;
            $taxPercent = $validated['tax_percent'] ?? 0;
            $discountAmount = ($subtotal * $discountPercent) / 100;
            $taxAmount = (($subtotal - $discountAmount) * $taxPercent) / 100;
            $totalAmount = $subtotal - $discountAmount + $taxAmount;

            // Generate invoice number
            $lastSale = Sale::where('tenant_id', $tenantId)
                ->whereDate('created_at', today())
                ->orderBy('id', 'desc')
                ->first();
            $counter = $lastSale ? (int)substr($lastSale->invoice_number, -4) + 1 : 1;
            $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($counter, 4, '0', STR_PAD_LEFT);

            // Create sale
            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'invoice_number' => $invoiceNumber,
                'customer_id' => $validated['customer_id'] ?? null,
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'sale_date' => now(),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentStatus === 'paid' ? $validated['payment_method'] : null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Create sale items and handle stock
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                $branchStock = BranchStock::where('product_id', $item['product_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->first();

                $currentStock = $branchStock->stock ?? 0;
                $reservedQty = $branchStock->reserved_quantity ?? 0;
                $availableStock = $currentStock - $reservedQty;

                // Check stock availability for physical products
                if ($product->type !== 'service' && $availableStock < $item['quantity']) {
                    throw new \Exception("Stok {$product->name} tidak mencukupi. Tersedia: {$availableStock}, Diminta: {$item['quantity']}");
                }

                $originalPrice = $branchStock->selling_price ?? $product->selling_price ?? $item['unit_price'];
                $priceAdjusted = abs($item['unit_price'] - $originalPrice) > 0.01;

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_type' => $product->type,
                    'description' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'original_price' => $originalPrice,
                    'price_adjusted' => $priceAdjusted,
                    'adjustment_reason' => $priceAdjusted ? ($item['adjustment_reason'] ?? null) : null,
                    'discount_amount' => 0,
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                    'warranty_days' => $item['warranty_days'] ?? null,
                    'warranty_expires_at' => ($paymentStatus === 'paid' && !empty($item['warranty_days']))
                        ? now()->addDays($item['warranty_days'])->toDateString()
                        : null,
                ]);

                if ($product->type !== 'service' && $branchStock) {
                    if ($paymentStatus === 'paid') {
                        // Immediate sale: decrement stock
                        $stockBefore = $branchStock->stock;
                        $branchStock->decrement('stock', $item['quantity']);
                        $branchStock->refresh();

                        StockMovement::create([
                            'tenant_id' => $tenantId,
                            'branch_id' => $validated['branch_id'],
                            'product_id' => $item['product_id'],
                            'movement_type' => StockMovement::TYPE_OUT,
                            'reference_type' => 'sale',
                            'reference_id' => $sale->id,
                            'reference_number' => $sale->invoice_number,
                            'quantity' => -$item['quantity'],
                            'quantity_before' => $stockBefore,
                            'quantity_after' => $branchStock->stock,
                            'notes' => "Sale: {$sale->invoice_number}",
                            'created_by' => $userId,
                        ]);
                    } else {
                        // Pending sale: reserve stock only
                        $branchStock->increment('reserved_quantity', $item['quantity']);
                    }
                }
            }

            DB::commit();

            $sale->load(['items.product', 'branch', 'customer']);

            return response()->json([
                'success' => true,
                'message' => $paymentStatus === 'paid'
                    ? 'Transaksi berhasil disimpan'
                    : 'Transaksi terbuka berhasil dibuat',
                'data' => $sale,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat transaksi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add items to a pending (open) sale
     */
    public function addItems(Request $request, $id)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.adjustment_reason' => 'nullable|string|max:500',
            'items.*.warranty_days' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();
        $sale = Sale::where('tenant_id', $user->tenant_id)->findOrFail($id);

        if (!$sale->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya bisa menambah item pada transaksi yang belum dibayar',
            ], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                $branchStock = BranchStock::where('product_id', $item['product_id'])
                    ->where('branch_id', $sale->branch_id)
                    ->first();

                $availableStock = $branchStock ? $branchStock->available_stock : 0;

                if ($product->type !== 'service' && $availableStock < $item['quantity']) {
                    throw new \Exception("Stok {$product->name} tidak mencukupi. Tersedia: {$availableStock}, Diminta: {$item['quantity']}");
                }

                $originalPrice = $branchStock->selling_price ?? $product->selling_price ?? $item['unit_price'];
                $priceAdjusted = abs($item['unit_price'] - $originalPrice) > 0.01;

                // Check if item already exists in this sale (merge quantity)
                $existingItem = SaleItem::where('sale_id', $sale->id)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if ($existingItem) {
                    $existingItem->increment('quantity', $item['quantity']);
                    $existingItem->update([
                        'subtotal' => ($existingItem->quantity) * $existingItem->unit_price,
                    ]);
                } else {
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $item['product_id'],
                        'product_type' => $product->type,
                        'description' => $product->name,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'original_price' => $originalPrice,
                        'price_adjusted' => $priceAdjusted,
                        'adjustment_reason' => $priceAdjusted ? ($item['adjustment_reason'] ?? null) : null,
                        'discount_amount' => 0,
                        'subtotal' => $item['quantity'] * $item['unit_price'],
                        'warranty_days' => $item['warranty_days'] ?? null,
                    ]);
                }

                // Reserve stock
                if ($product->type !== 'service' && $branchStock) {
                    $branchStock->increment('reserved_quantity', $item['quantity']);
                }
            }

            // Recalculate sale totals
            $this->recalculateSaleTotals($sale);

            DB::commit();

            $sale->load(['items.product', 'branch', 'customer']);

            return response()->json([
                'success' => true,
                'message' => 'Item berhasil ditambahkan',
                'data' => $sale,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah item: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove an item from a pending sale
     */
    public function removeItem(Request $request, $saleId, $itemId)
    {
        $user = $request->user();
        $sale = Sale::where('tenant_id', $user->tenant_id)->findOrFail($saleId);

        if (!$sale->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya bisa menghapus item pada transaksi yang belum dibayar',
            ], 422);
        }

        $saleItem = SaleItem::where('sale_id', $sale->id)->findOrFail($itemId);

        DB::beginTransaction();
        try {
            // Release reserved stock
            if ($saleItem->product_type !== 'service') {
                $branchStock = BranchStock::where('product_id', $saleItem->product_id)
                    ->where('branch_id', $sale->branch_id)
                    ->first();

                if ($branchStock) {
                    $branchStock->decrement('reserved_quantity', $saleItem->quantity);
                    // Ensure reserved doesn't go below 0
                    if ($branchStock->fresh()->reserved_quantity < 0) {
                        $branchStock->update(['reserved_quantity' => 0]);
                    }
                }
            }

            $saleItem->delete();

            // Recalculate sale totals
            $this->recalculateSaleTotals($sale);

            DB::commit();

            $sale->load(['items.product', 'branch', 'customer']);

            return response()->json([
                'success' => true,
                'message' => 'Item berhasil dihapus',
                'data' => $sale,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus item: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pay (finalize) a pending sale
     */
    public function pay(Request $request, $id)
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:cash,transfer,card,qris',
        ]);

        $user = $request->user();
        $sale = Sale::where('tenant_id', $user->tenant_id)->findOrFail($id);

        if (!$sale->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi ini sudah dibayar',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $sale->load('items');

            foreach ($sale->items as $saleItem) {
                if ($saleItem->product_type === 'service') continue;

                $branchStock = BranchStock::where('product_id', $saleItem->product_id)
                    ->where('branch_id', $sale->branch_id)
                    ->first();

                if (!$branchStock) continue;

                // Release reservation and decrement actual stock
                $branchStock->decrement('reserved_quantity', $saleItem->quantity);
                if ($branchStock->fresh()->reserved_quantity < 0) {
                    $branchStock->update(['reserved_quantity' => 0]);
                }

                $stockBefore = $branchStock->fresh()->stock;
                $branchStock->decrement('stock', $saleItem->quantity);
                $branchStock->refresh();

                // Create stock movement
                StockMovement::create([
                    'tenant_id' => $sale->tenant_id,
                    'branch_id' => $sale->branch_id,
                    'product_id' => $saleItem->product_id,
                    'movement_type' => StockMovement::TYPE_OUT,
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                    'reference_number' => $sale->invoice_number,
                    'quantity' => -$saleItem->quantity,
                    'quantity_before' => $stockBefore,
                    'quantity_after' => $branchStock->stock,
                    'notes' => "Sale (paid): {$sale->invoice_number}",
                    'created_by' => $user->id,
                ]);

                // Set warranty expiry
                if ($saleItem->warranty_days) {
                    $saleItem->update([
                        'warranty_expires_at' => now()->addDays($saleItem->warranty_days)->toDateString(),
                    ]);
                }
            }

            // Update sale status
            $sale->update([
                'payment_status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'sale_date' => now(), // Update sale_date to payment time
            ]);

            DB::commit();

            $sale->load(['items.product', 'branch', 'customer']);

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil dibayar',
                'data' => $sale,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pembayaran: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recalculate sale totals based on current items
     */
    private function recalculateSaleTotals(Sale $sale)
    {
        $sale->refresh();
        $items = $sale->items;
        $subtotal = $items->sum('subtotal');

        // Keep existing discount/tax percentages
        $discountPercent = $sale->subtotal > 0
            ? ($sale->discount_amount / $sale->subtotal) * 100
            : 0;
        $discountAmount = ($subtotal * $discountPercent) / 100;

        $taxPercent = ($sale->subtotal - $sale->discount_amount) > 0
            ? ($sale->tax_amount / ($sale->subtotal - $sale->discount_amount)) * 100
            : 0;
        $taxAmount = (($subtotal - $discountAmount) * $taxPercent) / 100;

        $sale->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $subtotal - $discountAmount + $taxAmount,
        ]);
    }

    /**
     * Delete a sale transaction with full audit log.
     * Snapshots all data, restores stock, and records deletion reason.
     */
    public function destroy(Request $request, $id)
    {
        $request->validate([
            'deletion_reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        $sale = Sale::where('tenant_id', $tenantId)
            ->with(['items.product', 'branch', 'customer', 'vehicle', 'creator'])
            ->findOrFail($id);

        DB::beginTransaction();
        try {
            // Snapshot the full sale data
            $saleSnapshot = $sale->toArray();
            $itemsSnapshot = $sale->items->toArray();

            // Create deletion log
            SaleDeletionLog::create([
                'tenant_id' => $tenantId,
                'branch_id' => $sale->branch_id,
                'invoice_number' => $sale->invoice_number,
                'sale_data' => $saleSnapshot,
                'items_data' => $itemsSnapshot,
                'total_amount' => $sale->total_amount,
                'customer_name' => $sale->customer?->name,
                'deleted_by' => $user->id,
                'deletion_reason' => $request->deletion_reason,
                'original_sale_date' => $sale->sale_date,
            ]);

            // Restore stock for physical products (to branch_stocks)
        foreach ($sale->items as $item) {
            $product = $item->product;
            if ($product && $product->type !== 'service') {
                // Find or create branch stock record
                $branchStock = BranchStock::firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'branch_id'  => $sale->branch_id,
                    ],
                    [
                        'tenant_id' => $tenantId,
                        'stock'     => 0,
                        'min_stock' => 0,
                    ]
                );

                $stockBefore = $branchStock->stock;
                $branchStock->increment('stock', $item->quantity);
                $branchStock->refresh();

                // Create stock movement for the restoration
                StockMovement::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $sale->branch_id,
                    'product_id' => $item->product_id,
                    'movement_type' => StockMovement::TYPE_IN,
                    'reference_type' => 'sale_deletion',
                    'reference_id' => $sale->id,
                    'reference_number' => $sale->invoice_number,
                    'quantity' => $item->quantity,
                    'quantity_before' => $stockBefore,
                    'quantity_after' => $branchStock->stock,
                    'notes' => "Stock restored - Sale deleted: {$sale->invoice_number}",
                    'created_by' => $user->id,
                ]);
            }
        }

            // Delete sale items and sale
            $sale->items()->delete();
            $sale->delete();

            DB::commit();

            // Log activity
            activity()
                ->causedBy($user)
                ->withProperties([
                    'invoice_number' => $sale->invoice_number,
                    'total_amount' => $sale->total_amount,
                    'reason' => $request->deletion_reason,
                ])
                ->log("Deleted sale {$sale->invoice_number}");

            return response()->json([
                'success' => true,
                'message' => "Transaksi {$sale->invoice_number} berhasil dihapus",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus transaksi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get deletion logs with filters
     */
    public function deletionLogs(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = SaleDeletionLog::where('tenant_id', $tenantId)
            ->with(['branch', 'deletedBy']);

        // Filter by branch
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

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Search by invoice number
        if ($request->has('search')) {
            $query->where('invoice_number', 'like', "%{$request->search}%");
        }

        $logs = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
