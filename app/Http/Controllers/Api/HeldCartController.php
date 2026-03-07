<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HeldCart;
use Illuminate\Http\Request;

class HeldCartController extends Controller
{
    /**
     * List held carts for the current branch
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = HeldCart::where('tenant_id', $tenantId)
            ->with(['customer:id,name,phone,customer_type', 'heldByUser:id,name', 'branch:id,name'])
            ->latest();

        // Filter by branch
        if ($request->has('branch_id') && $request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        } elseif (!$user->hasRole('owner')) {
            $branchId = $user->employee->branch_id ?? $user->branches()->first()?->id;
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }

        $heldCarts = $query->get();

        return response()->json([
            'success' => true,
            'data' => $heldCarts,
        ]);
    }

    /**
     * Hold (park) the current cart
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'nullable|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.original_price' => 'nullable|numeric|min:0',
            'items.*.adjustment_reason' => 'nullable|string',
            'items.*.total' => 'required|numeric',
            'label' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        $heldCart = HeldCart::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $validated['branch_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'items' => $validated['items'],
            'label' => $validated['label'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'held_by' => $user->id,
        ]);

        $heldCart->load(['customer:id,name,phone,customer_type', 'heldByUser:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Transaksi berhasil di-hold',
            'data' => $heldCart,
        ], 201);
    }

    /**
     * Get a single held cart (for resuming)
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $heldCart = HeldCart::where('tenant_id', $user->tenant_id)
            ->with(['customer:id,name,phone,customer_type,discount_percentage', 'heldByUser:id,name'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $heldCart,
        ]);
    }

    /**
     * Delete a held cart (after resuming or cancelling)
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $heldCart = HeldCart::where('tenant_id', $user->tenant_id)->findOrFail($id);
        $heldCart->delete();

        return response()->json([
            'success' => true,
            'message' => 'Held cart berhasil dihapus',
        ]);
    }
}
