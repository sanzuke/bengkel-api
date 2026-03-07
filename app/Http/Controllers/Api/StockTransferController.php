<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class StockTransferController extends Controller
{
    /**
     * List Transfers
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = StockTransfer::where('tenant_id', $user->tenant_id)
            ->with(['fromBranch', 'toBranch', 'creator', 'receiver', 'items.product']);

        // Filter by branch involvement
        if (!$user->hasRole('owner')) {
            $branchId = $user->employee->branch_id ?? $user->branches()->first()?->id;
            if ($branchId) {
                $query->where(function($q) use ($branchId) {
                    $q->where('from_branch_id', $branchId)
                      ->orWhere('to_branch_id', $branchId);
                });
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $transfers,
        ]);
    }

    /**
     * Store Initial Transfer (Pending)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_branch_id' => 'required|exists:branches,id',
            'to_branch_id' => 'required|exists:branches,id|different:from_branch_id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $tenantId, $userId) {
            // Generate transfer number: TRF-YYYYMMDD-XXX
            $dateStr = date('Ymd');
            $count = StockTransfer::where('tenant_id', $tenantId)
                ->whereDate('created_at', date('Y-m-d'))
                ->count() + 1;
            $transferNumber = 'TRF-' . $dateStr . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

            $transfer = StockTransfer::create([
                'tenant_id' => $tenantId,
                'from_branch_id' => $validated['from_branch_id'],
                'to_branch_id' => $validated['to_branch_id'],
                'transfer_number' => $transferNumber,
                'status' => 'pending',
                'notes' => $validated['notes'],
                'created_by' => $userId,
            ]);

            foreach ($validated['items'] as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transfer stock berhasil dibuat (Status: Pending)',
                'data' => $transfer->load('items.product'),
            ], 201);
        });
    }

    /**
     * Ship Transfer (Deduct from Source)
     */
    public function ship(Request $request, $id)
    {
        $transfer = StockTransfer::where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'pending')
            ->findOrFail($id);

        return DB::transaction(function () use ($transfer, $request) {
            foreach ($transfer->items as $item) {
                $sourceStock = BranchStock::where('branch_id', $transfer->from_branch_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if (!$sourceStock || $sourceStock->stock < $item->quantity) {
                    throw new Exception("Stok produk {$item->product->name} tidak cukup di cabang asal.");
                }

                $quantityBefore = $sourceStock->stock;
                $sourceStock->decrement('stock', $item->quantity);
                $sourceStock->refresh();

                // Log Movement
                StockMovement::create([
                    'tenant_id' => $transfer->tenant_id,
                    'branch_id' => $transfer->from_branch_id,
                    'product_id' => $item->product_id,
                    'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->id,
                    'reference_number' => $transfer->transfer_number,
                    'quantity' => $item->quantity,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $sourceStock->stock,
                    'created_by' => $request->user()->id,
                    'notes' => "Transfer ke " . $transfer->toBranch->name,
                ]);
            }

            $transfer->update([
                'status' => 'shipped',
                'shipped_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil dikirim dan stok cabang asal telah dikurangi',
                'data' => $transfer,
            ]);
        });
    }

    /**
     * Receive Transfer (Verified Checklist & Add to Target)
     */
    public function receive(Request $request, $id)
    {
        $transfer = StockTransfer::where('tenant_id', $request->user()->tenant_id)
            ->where('status', 'shipped')
            ->findOrFail($id);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:stock_transfer_items,id',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($transfer, $validated, $request) {
            $mismatchDetected = false;

            foreach ($validated['items'] as $vItem) {
                $item = StockTransferItem::findOrFail($vItem['id']);
                $item->update(['received_quantity' => $vItem['received_quantity']]);

                if ($item->quantity != $item->received_quantity) {
                    $mismatchDetected = true;
                }

                // Add to Target Branch Stock
                $targetStock = BranchStock::firstOrCreate(
                    [
                        'tenant_id' => $transfer->tenant_id,
                        'branch_id' => $transfer->to_branch_id,
                        'product_id' => $item->product_id,
                    ],
                    ['stock' => 0, 'min_stock' => 0]
                );

                $quantityBefore = $targetStock->stock;
                $targetStock->increment('stock', $item->received_quantity);
                $targetStock->refresh();

                // Log Movement
                StockMovement::create([
                    'tenant_id' => $transfer->tenant_id,
                    'branch_id' => $transfer->to_branch_id,
                    'product_id' => $item->product_id,
                    'movement_type' => StockMovement::TYPE_TRANSFER_IN,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->id,
                    'reference_number' => $transfer->transfer_number,
                    'quantity' => $item->received_quantity,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $targetStock->stock,
                    'created_by' => $request->user()->id,
                    'notes' => "Transfer dari " . $transfer->fromBranch->name,
                ]);
            }

            $transfer->update([
                'status' => 'received',
                'received_at' => now(),
                'received_by' => $request->user()->id,
                'notes' => ($transfer->notes ? $transfer->notes . "\n" : "") . ($request->notes ?: "") . ($mismatchDetected ? "\n(VERIFIKASI: Ada ketidakcocokan jumlah!)" : ""),
            ]);

            return response()->json([
                'success' => true,
                'message' => $mismatchDetected ? 'Barang diterima dengan catatan ketidakcocokan' : 'Barang diterima dengan sukses',
                'data' => $transfer,
            ]);
        });
    }

    /**
     * Cancel Transfer
     */
    public function cancel(Request $request, $id)
    {
        $transfer = StockTransfer::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('status', ['pending', 'shipped'])
            ->findOrFail($id);

        return DB::transaction(function () use ($transfer, $request) {
            if ($transfer->status === 'shipped') {
                // Return stock to source
                foreach ($transfer->items as $item) {
                    $sourceStock = BranchStock::where('branch_id', $transfer->from_branch_id)
                        ->where('product_id', $item->product_id)
                        ->first();
                    
                    if ($sourceStock) {
                        $sourceStock->increment('stock', $item->quantity);
                        
                        // Log Movement (Optional: Cancellation Movement)
                        StockMovement::create([
                            'tenant_id' => $transfer->tenant_id,
                            'branch_id' => $transfer->from_branch_id,
                            'product_id' => $item->product_id,
                            'movement_type' => StockMovement::TYPE_IN,
                            'reference_type' => 'stock_transfer_cancel',
                            'reference_id' => $transfer->id,
                            'reference_number' => $transfer->transfer_number,
                            'quantity' => $item->quantity,
                            'quantity_before' => $sourceStock->stock - $item->quantity,
                            'quantity_after' => $sourceStock->stock,
                            'created_by' => $request->user()->id,
                            'notes' => "Pembatalan transfer TRF-" . $transfer->transfer_number,
                        ]);
                    }
                }
            }

            $transfer->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => 'Transfer berhasil dibatalkan',
            ]);
        });
    }
}
