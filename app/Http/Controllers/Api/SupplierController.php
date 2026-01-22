<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    /**
     * Get all suppliers with pagination
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = Supplier::where('tenant_id', $tenantId);

        // Search by name, code, or contact
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('contact_person', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $suppliers = $query->orderBy('name')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $suppliers,
        ]);
    }

    /**
     * Create a new supplier
     */
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'npwp' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Auto-generate code if not provided
        if (empty($validated['code'])) {
            $lastSupplier = Supplier::where('tenant_id', $tenantId)
                ->orderBy('id', 'desc')
                ->first();
            $counter = $lastSupplier ? (int)substr($lastSupplier->code ?? 'SUP-0000', -4) + 1 : 1;
            $validated['code'] = 'SUP-' . str_pad($counter, 4, '0', STR_PAD_LEFT);
        }

        $supplier = Supplier::create([
            'tenant_id' => $tenantId,
            ...$validated,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supplier created successfully',
            'data' => $supplier,
        ], 201);
    }

    /**
     * Get supplier detail
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $supplier = Supplier::where('tenant_id', $tenantId)
            ->with(['products'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $supplier,
        ]);
    }

    /**
     * Update supplier
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                })->ignore($supplier->id),
            ],
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'npwp' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $supplier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Supplier updated successfully',
            'data' => $supplier,
        ]);
    }

    /**
     * Delete supplier
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($id);

        // Check if supplier has products
        if ($supplier->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete supplier with associated products',
            ], 400);
        }

        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Supplier deleted successfully',
        ]);
    }

    /**
     * Toggle supplier active status
     */
    public function toggleStatus(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($id);

        $supplier->update(['is_active' => !$supplier->is_active]);

        return response()->json([
            'success' => true,
            'message' => $supplier->is_active ? 'Supplier activated' : 'Supplier deactivated',
            'data' => $supplier,
        ]);
    }
}
