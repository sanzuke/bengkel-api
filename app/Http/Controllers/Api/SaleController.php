<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Sale, SaleItem, Product, StockMovement};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    /**
     * Get products for POS (search & filter)
     */
    public function searchProducts(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = Product::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('category');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Filter by branch
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

        $products = $query->limit(20)->get();

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
            'payment_method' => 'required|in:cash,transfer,card,qris',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            // Calculate totals
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            // Calculate discount and tax from percentages
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
                'payment_status' => 'paid',
                'payment_method' => $validated['payment_method'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
            ]);

            // Create sale items and update stock
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                
                // Check stock availability only for physical products (not services)
                if ($product->type !== 'service' && $product->stock < $item['quantity']) {
                    throw new \Exception("Stok {$product->name} tidak mencukupi. Tersedia: {$product->stock}, Diminta: {$item['quantity']}");
                }
                
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_type' => $product->type, // Use actual product type
                    'description' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => 0,
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                ]);
                
                // Only decrement stock and create stock movement for physical products
                if ($product->type !== 'service') {
                    // Decrement stock
                    $product->decrement('stock', $item['quantity']);

                    // Create Stock Movement record
                    StockMovement::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $validated['branch_id'],
                        'product_id' => $item['product_id'],
                        'movement_type' => StockMovement::TYPE_OUT,
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'reference_number' => $sale->invoice_number,
                        'quantity' => -$item['quantity'],
                        'quantity_before' => $product->stock + $item['quantity'],
                        'quantity_after' => $product->stock,
                        'notes' => "Sale: {$sale->invoice_number}",
                        'created_by' => $userId,
                    ]);
                }
            }

            DB::commit();

            $sale->load(['items.product', 'branch', 'customer']);

            return response()->json([
                'success' => true,
                'message' => 'Sale created successfully',
                'data' => $sale,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create sale: ' . $e->getMessage(),
            ], 500);
        }
    }
}
